<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Duplicates\Merger;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use App\Rules\Siret;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function mgSiret(): string
{
    for ($check = 0; $check <= 9; $check++) {
        if (Siret::isValid('7328293200007'.$check)) {
            return '7328293200007'.$check;
        }
    }
    throw new RuntimeException('unreachable');
}

/** @param array<string, mixed> $attributes */
function mgOrg(string $name, array $attributes = []): Organisation
{
    return Organisation::query()->create(['name' => $name, ...$attributes]);
}

function mgNote(Organisation|Person $subject, string $body): Note
{
    $note = new Note(['body' => $body]);
    $note->notable()->associate($subject);
    $note->save();

    return $note;
}

function mgAttachment(Organisation|Person $subject, string $name): Attachment
{
    $attachment = new Attachment(['original_name' => $name, 'mime' => 'application/pdf', 'size' => 10, 'path' => 'tenants/x/attachments/'.fake()->uuid()]);
    $attachment->notable()->associate($subject);
    $attachment->save();

    return $attachment;
}

function mgDas(string $label): ReferenceItem
{
    return crmItem(ReferenceList::Das, $label);
}

function mgFirstDas(int $offset = 0): ReferenceItem
{
    return ReferenceItem::query()->where('list', ReferenceList::Das)->orderBy('position')->skip($offset)->firstOrFail();
}

it('merges two entreprises field by field and moves everything to the kept one', function () {
    $user = commercial();
    $this->actingAs($user);
    $keep = mgOrg('Hôtel du Lac', ['city' => 'Annecy', 'email' => 'old@lac.fr', 'source_system' => 'formevol', 'source_id' => 'K1']);
    $drop = mgOrg('Hotel du Lac SARL', ['city' => 'Annecy-le-Vieux', 'email' => 'contact@lac.fr', 'phone' => '0450000000', 'siret' => mgSiret(),
        'source_system' => 'formevol', 'source_id' => 'D1', 'last_contact_at' => now()->subDay()]);
    $keep->das()->sync([mgFirstDas()->id => ['tenant_id' => tenant()->id]]);
    $drop->das()->sync([mgFirstDas()->id => ['tenant_id' => tenant()->id], mgFirstDas(1)->id => ['tenant_id' => tenant()->id]]);
    $note = mgNote($drop, 'Appel');
    $trashedNote = mgNote($drop, 'Effacée');
    $trashedNote->delete();
    $attachment = mgAttachment($drop, 'devis.pdf');
    $person = makePerson('Roux');
    $moved = affiliate($person, $drop, ['job_title' => 'Gérant']);

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => ['email' => 'drop', 'city' => 'keep']])
        ->assertRedirect(route('crm.organisations.show', $keep));

    $keep->refresh();
    $drop = Organisation::withTrashed()->findOrFail($drop->id);
    expect($keep->name)->toBe('Hôtel du Lac')
        ->and($keep->email)->toBe('contact@lac.fr')
        ->and($keep->city)->toBe('Annecy')
        // Empty on the kept fiche: taken from the other one by default.
        ->and($keep->phone)->toBe('0450000000')
        ->and($keep->siret)->toBe(mgSiret())
        ->and($keep->last_contact_at?->toIso8601String())->toBe($drop->last_contact_at?->toIso8601String())
        ->and($keep->source_id)->toBe('K1')
        ->and($keep->merged_sources)->toBe([['id' => $drop->id, 'source_id' => 'D1', 'source_system' => 'formevol']])
        ->and($keep->das()->pluck('reference_items.id')->sort()->values()->all())->toBe(collect([mgFirstDas()->id, mgFirstDas(1)->id])->sort()->values()->all())
        ->and($drop->trashed())->toBeTrue()
        ->and($drop->merged_into_id)->toBe($keep->id)
        ->and($drop->siret)->toBe(mgSiret())
        ->and($note->refresh()->notable_id)->toBe($keep->id)
        ->and(Note::withTrashed()->findOrFail($trashedNote->id)->notable_id)->toBe($keep->id)
        ->and($attachment->refresh()->notable_id)->toBe($keep->id)
        ->and($moved->refresh()->organisation_id)->toBe($keep->id)
        ->and($moved->isActive())->toBeTrue();

    $log = AuditLog::query()->where('event', 'crm.merged')->sole();
    expect($log->auditable_id)->toBe($keep->id)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->new_values)->toBe(['type' => 'organisation', 'kept_id' => $keep->id, 'dropped_id' => $drop->id]);

    $this->getJson("/crm/organisations/{$keep->id}/timeline")->assertOk()
        ->assertJsonFragment(['title' => 'Fiche en double fusionnée dans cette fiche'])
        ->assertJsonFragment(['body' => 'Appel']);
});

it('merging people deduplicates affiliations', function () {
    $this->actingAs(commercial());
    $org = mgOrg('Hôtel du Lac');
    $elsewhere = mgOrg('Autre SA');
    $keep = makePerson('Dupont', ['first_name' => 'Jean', 'email' => 'jean@dupont.fr']);
    $drop = makePerson('Dupond', ['first_name' => 'Jean', 'mobile' => '0612345678', 'birth_date' => '1980-05-01']);
    $keepLink = affiliate($keep, $org, ['started_on' => '2024-01-01', 'job_title' => null, 'work_email' => 'jean@lac.fr']);
    $dropLink = affiliate($drop, $org, ['started_on' => '2022-06-01', 'job_title' => 'Directeur', 'work_phone' => '0450000000', 'is_referent' => true]);
    $ended = affiliate($drop, $org, ['started_on' => '2010-01-01', 'ended_on' => '2015-01-01', 'job_title' => 'Stagiaire']);
    $other = affiliate($drop, $elsewhere, ['job_title' => 'Consultant']);
    $note = mgNote($drop, 'Rencontré au salon');
    $attachment = mgAttachment($drop, 'cv.pdf');

    $this->post('/crm/merge/person', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.people.show', $keep));

    // One active link to the entreprise: the oldest, completed by the other.
    $active = Affiliation::query()->where('person_id', $keep->id)->where('organisation_id', $org->id)->whereNull('ended_on')->sole();
    expect($active->id)->toBe($dropLink->id)
        ->and($active->started_on?->toDateString())->toBe('2022-06-01')
        ->and($active->job_title)->toBe('Directeur')
        ->and($active->work_email)->toBe('jean@lac.fr')
        ->and($active->work_phone)->toBe('+33450000000')
        ->and($active->is_referent)->toBeTrue()
        ->and(Affiliation::withTrashed()->findOrFail($keepLink->id)->trashed())->toBeTrue()
        ->and($ended->refresh()->person_id)->toBe($keep->id)
        ->and($ended->trashed())->toBeFalse()
        ->and($other->refresh()->person_id)->toBe($keep->id)
        ->and(Affiliation::withTrashed()->where('person_id', $drop->id)->count())->toBe(0)
        ->and($note->refresh()->notable_id)->toBe($keep->id)
        ->and($attachment->refresh()->notable_id)->toBe($keep->id);

    $keep->refresh();
    expect($keep->last_name)->toBe('Dupont')
        ->and($keep->mobile)->toBe('+33612345678')
        ->and($keep->birth_date?->toDateString())->toBe('1980-05-01')
        ->and(Person::withTrashed()->findOrFail($drop->id)->merged_into_id)->toBe($keep->id);
});

it('keeps the kept link when it is the oldest, and merges shared people of two entreprises', function () {
    $this->actingAs(commercial());
    $keep = mgOrg('Hôtel du Lac');
    $drop = mgOrg('Hôtel du Lac bis');
    $person = makePerson('Roux');
    $keepLink = affiliate($person, $keep, ['started_on' => '2020-01-01']);
    $dropLink = affiliate($person, $drop, ['started_on' => null, 'job_title' => 'Gérant']);

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id])->assertRedirect();

    $active = Affiliation::query()->where('person_id', $person->id)->sole();
    expect($active->id)->toBe($keepLink->id)
        ->and($active->organisation_id)->toBe($keep->id)
        ->and($active->job_title)->toBe('Gérant')
        ->and(Affiliation::withTrashed()->findOrFail($dropLink->id)->trashed())->toBeTrue();
});

it('never writes personal values in the journal when merging people', function () {
    $this->actingAs(commercial());
    $keep = makePerson('Dupont', ['first_name' => 'Jean']);
    $drop = makePerson('Dupondissime', ['first_name' => 'Jeannot', 'email' => 'jeannot@secret.fr', 'mobile' => '0698765432']);
    affiliate($drop, mgOrg('Lac'), ['work_email' => 'pro@secret.fr']);

    $this->post('/crm/merge/person', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => ['last_name' => 'drop', 'first_name' => 'drop']])->assertRedirect();

    expect($keep->refresh()->last_name)->toBe('Dupondissime');
    $journal = AuditLog::query()->where('created_at', '>=', now()->subMinute())->get()
        ->map(fn (AuditLog $l) => json_encode([$l->old_values, $l->new_values], JSON_UNESCAPED_UNICODE))->implode("\n");
    expect($journal)->not->toContain('Dupondissime')->not->toContain('Jeannot')->not->toContain('secret.fr')->not->toContain('98765432')
        ->and(AuditLog::query()->where('event', 'crm.merged')->sole()->new_values)->toBe(['type' => 'person', 'kept_id' => $keep->id, 'dropped_id' => $drop->id]);
});

it('redirects every visit of the merged fiche to the kept one', function () {
    $this->actingAs(commercial());
    $keep = mgOrg('Garde');
    $drop = mgOrg('Absorbée');
    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id])->assertRedirect();

    $this->get("/crm/organisations/{$drop->id}")->assertRedirect("/crm/organisations/{$keep->id}");
    $this->get("/crm/organisations/{$drop->id}/edit")->assertRedirect("/crm/organisations/{$keep->id}/edit");
    // A write on the merged fiche never reaches the kept one (303 = see other, as GET).
    $this->delete("/crm/organisations/{$drop->id}")->assertStatus(303)->assertRedirect("/crm/organisations/{$keep->id}");
    $this->post("/crm/organisations/{$drop->id}/restore")->assertStatus(303);
    expect($keep->refresh()->trashed())->toBeFalse()->and(Organisation::withTrashed()->findOrFail($drop->id)->trashed())->toBeTrue();

    // Merged fiches are not in the trash: they cannot be restored.
    $this->get('/crm/organisations?trashed=1')->assertInertia(fn (Assert $page) => $page->has('organisations.data', 0));

    $pKeep = makePerson('Garde');
    $pDrop = makePerson('Absorbée');
    $this->post('/crm/merge/person', ['keep' => $pKeep->id, 'drop' => $pDrop->id])->assertRedirect();
    $this->get("/crm/people/{$pDrop->id}")->assertRedirect("/crm/people/{$pKeep->id}");
    $this->get('/crm/people?trashed=1')->assertInertia(fn (Assert $page) => $page->has('people.data', 0));

    // A chain of merges ends on the last kept fiche.
    $last = mgOrg('Dernière');
    $this->post('/crm/merge/organisation', ['keep' => $last->id, 'drop' => $keep->id])->assertRedirect();
    $this->get("/crm/organisations/{$drop->id}")->assertRedirect("/crm/organisations/{$last->id}");
});

it('refuses a model-level restore of a merged fiche', function () {
    $this->actingAs(commercial());
    $keep = mgOrg('Garde');
    $drop = mgOrg('Absorbée');
    app(Merger::class)->organisations($keep, $drop, []);

    expect(Organisation::withTrashed()->findOrFail($drop->id)->restore())->toBeFalse()
        ->and(Organisation::withTrashed()->findOrFail($drop->id)->trashed())->toBeTrue();
});

it('shows the side-by-side merge screen', function () {
    $this->actingAs(commercial());
    $keep = mgOrg('Hôtel du Lac', ['city' => 'Annecy']);
    $drop = mgOrg('Hotel du Lac SARL', ['city' => 'Annecy', 'email' => 'lac@hotel.fr']);
    mgNote($drop, 'x');

    $this->get("/crm/merge/organisation/{$keep->id}/{$drop->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('crm/duplicates/merge')
        ->where('type', 'organisation')
        ->where('keep.id', $keep->id)
        ->where('drop.id', $drop->id)
        ->where('fields', fn ($fields) => collect($fields)->firstWhere('name', 'name')['label'] === 'Raison sociale'
            && collect($fields)->firstWhere('name', 'name')['keep'] === 'Hôtel du Lac'
            && collect($fields)->firstWhere('name', 'name')['default'] === 'keep'
            && collect($fields)->firstWhere('name', 'email')['default'] === 'drop'
            && collect($fields)->firstWhere('name', 'city')['same'] === true)
        ->where('counts.drop.notes', 1));

    $pKeep = makePerson('Dupont', ['first_name' => 'Jean']);
    $pDrop = makePerson('Dupond', ['first_name' => 'Jean']);
    $this->get("/crm/merge/person/{$pKeep->id}/{$pDrop->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('fields', fn ($fields) => collect($fields)->firstWhere('name', 'last_name')['label'] === 'Nom'));
});

it('refuses impossible merges', function () {
    $this->actingAs(commercial());
    $a = mgOrg('A');
    $b = mgOrg('B');
    $person = makePerson('P');

    $this->postJson('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $a->id])->assertJsonValidationErrors('drop');
    $this->postJson('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $person->id])->assertJsonValidationErrors('drop');
    $this->postJson('/crm/merge/person', ['keep' => $person->id, 'drop' => $a->id])->assertJsonValidationErrors('drop');
    $this->postJson('/crm/merge/company', ['keep' => $a->id, 'drop' => $b->id])->assertNotFound();
    $this->postJson('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $b->id, 'fields' => ['name' => 'les deux']])->assertJsonValidationErrors('fields.name');
    $this->postJson('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $b->id, 'fields' => ['tenant_id' => 'drop']])->assertJsonValidationErrors('fields');
    $this->postJson('/crm/merge/organisation', ['keep' => 'pas-un-uuid', 'drop' => $b->id])->assertJsonValidationErrors('keep');

    $this->post('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $b->id])->assertRedirect();
    // Already merged, either way round.
    $c = mgOrg('C');
    $this->postJson('/crm/merge/organisation', ['keep' => $c->id, 'drop' => $b->id])->assertJsonValidationErrors('drop');
    $this->postJson('/crm/merge/organisation', ['keep' => $b->id, 'drop' => $c->id])->assertJsonValidationErrors('keep');
    // A trashed fiche is restored first, not merged.
    $c->delete();
    $this->postJson('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $c->id])->assertJsonValidationErrors('drop');
    $this->get("/crm/merge/organisation/{$a->id}/{$c->id}")->assertNotFound();

    expect(AuditLog::query()->where('event', 'crm.merged')->count())->toBe(1);
});

it('locks both fiches and re-checks them inside the transaction', function () {
    $this->actingAs(commercial());
    $keep = mgOrg('Garde');
    $drop = mgOrg('Absorbée');
    $stale = Organisation::query()->findOrFail($drop->id);

    app(Merger::class)->organisations($keep, $drop, []);

    // A second merge with a stale copy of the same fiche is refused.
    expect(fn () => app(Merger::class)->organisations(mgOrg('Autre'), $stale, []))
        ->toThrow(ValidationException::class);
    expect(Organisation::withTrashed()->findOrFail($drop->id)->merged_into_id)->toBe($keep->id);
});

it('rolls everything back when the merge fails half-way', function () {
    $this->actingAs(commercial());
    $keep = mgOrg('Garde');
    $drop = mgOrg('Absorbée', ['email' => 'a@b.fr']);
    $note = mgNote($drop, 'x');
    // Fails on the very last write (the kept fiche), after everything else moved.
    Organisation::updating(function (Organisation $o) use ($keep) {
        if ($o->id === $keep->id) {
            throw new RuntimeException('panne');
        }
    });

    expect(fn () => app(Merger::class)->organisations($keep, $drop, ['email' => 'drop']))->toThrow(RuntimeException::class, 'panne');

    expect($note->refresh()->notable_id)->toBe($drop->id)
        ->and(Organisation::query()->findOrFail($drop->id)->merged_into_id)->toBeNull()
        ->and($drop->refresh()->trashed())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'crm.merged')->count())->toBe(0);
});

it('needs crm.merge', function () {
    $a = mgOrg('A');
    $b = mgOrg('B');
    $this->actingAs(assistant());

    $this->get("/crm/merge/organisation/{$a->id}/{$b->id}")->assertForbidden();
    $this->post('/crm/merge/organisation', ['keep' => $a->id, 'drop' => $b->id])->assertForbidden();
    expect(Organisation::count())->toBe(2);
});

it('only merges fiches the user may see', function () {
    $das = mgDas('Hôtellerie-restauration');
    $user = userWithRole(Role::ResponsableDas);
    $user->givePermissionTo('crm.merge');
    followDas($user, $das);
    $visible = mgOrg('Visible');
    $visible->das()->sync([$das->id => ['tenant_id' => tenant()->id]]);
    $hidden = mgOrg('Cachée');
    $this->actingAs($user);

    $this->get("/crm/merge/organisation/{$visible->id}/{$hidden->id}")->assertNotFound();
    $this->postJson('/crm/merge/organisation', ['keep' => $visible->id, 'drop' => $hidden->id])->assertJsonValidationErrors('drop');
    expect(Organisation::count())->toBe(2);
});

it('creates then opens the merge screen when « Fusionner » is chosen on a new fiche', function () {
    $existing = mgOrg('Hôtel du Lac', ['email' => 'lac@hotel.fr']);
    $this->actingAs(commercial());

    $response = $this->post('/crm/organisations', ['name' => 'Hôtel du Lac', 'email' => 'lac@hotel.fr', 'confirm_duplicate' => 1, 'merge_into' => $existing->id]);

    $created = Organisation::query()->whereKeyNot($existing->id)->sole();
    $response->assertRedirect(route('crm.merge.show', ['type' => 'organisation', 'keep' => $existing->id, 'drop' => $created->id]));
});

it('lists similar fiches on a fiche for those who may merge', function () {
    $fiche = makePerson('Dupont', ['first_name' => 'Jean', 'city' => 'Annecy']);
    $twin = makePerson('Dupond', ['first_name' => 'Jean', 'city' => 'Annecy']);

    $this->actingAs(commercial())->get("/crm/people/{$fiche->id}")->assertInertia(fn (Assert $page) => $page
        ->where('similar.0.id', $twin->id)->where('similar.0.reason', 'name')->where('can.merge', true));
    $this->actingAs(assistant())->get("/crm/people/{$fiche->id}")->assertInertia(fn (Assert $page) => $page
        ->where('similar', [])->where('can.merge', false));
});
