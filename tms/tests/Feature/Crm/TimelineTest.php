<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use App\Modules\Crm\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function tlOrg(string $name = 'Hôtel du Lac', array $attributes = []): Organisation
{
    return Organisation::query()->create(['name' => $name, ...$attributes]);
}

function tlAttachment(Organisation|Person $subject, string $name = 'devis.pdf'): Attachment
{
    $attachment = new Attachment(['original_name' => $name, 'mime' => 'application/pdf', 'size' => 1234, 'path' => 'tenants/x/attachments/'.fake()->uuid()]);
    $attachment->notable()->associate($subject);
    $attachment->save();

    return $attachment;
}

function tlNote(Organisation|Person $subject, string $body = 'Appel de suivi'): Note
{
    $note = new Note(['body' => $body]);
    $note->notable()->associate($subject);
    $note->save();

    return $note;
}

it('merges notes, attachments and audit entries, most recent first', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00'));
    tlNote($org, 'Premier appel');
    $this->travelTo(CarbonImmutable::parse('2026-11-12 11:00:00'));
    tlAttachment($org);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:00'));
    $org->update(['city' => 'Annecy']);

    $entries = Timeline::for($org->fresh());

    expect($entries->pluck('type')->all())->toBe(['audit', 'attachment', 'note', 'audit'])
        ->and($entries->pluck('title')->all())->toBe(['Fiche modifiée (champs : Ville)', 'devis.pdf', 'Note', 'Fiche créée'])
        ->and($entries[2]->body)->toBe('Premier appel')
        ->and($entries[2]->authorName)->not->toBeNull();
});

it('translates audit entries: field names and status label, never values', function () {
    $this->actingAs(commercial());
    $org = tlOrg('Secret SA', ['status' => 'prospect']);

    $org->update(['status' => 'client']);
    $org->update(['status' => 'inactif', 'name' => 'Renommée SARL']);

    $titles = Timeline::for($org->fresh())->pluck('title')->all();

    expect($titles)->toContain('Fiche créée', 'Statut : Prospect → Client', 'Statut : Client → Inactif ; autres champs : Raison sociale')
        ->and(json_encode($titles))->not->toContain('Secret SA')->not->toContain('Renommée');
});

it('shows no personal value of a person in the feed', function () {
    $this->actingAs(commercial());
    $person = makePerson('Dupont', ['first_name' => 'Marie', 'email' => 'marie@example.org', 'city' => 'Lyon']);
    $this->travel(1)->seconds();
    $person->update(['last_name' => 'Martin-Secret', 'email' => 'autre@example.org']);

    $entries = Timeline::for($person->fresh());
    $json = json_encode($entries->map->toArray()->all(), JSON_UNESCAPED_UNICODE);

    expect($entries->pluck('title')->all())->toBe(['Fiche modifiée (champs : E-mail, Nom)', 'Fiche créée'])
        ->and($json)->not->toContain('Dupont')->not->toContain('Martin-Secret')->not->toContain('marie@example.org')->not->toContain('autre@example.org')->not->toContain('Lyon');

    $this->get("/crm/people/{$person->id}/timeline")->assertOk()->assertJsonCount(2, 'entries');
});

it('never copies a note text into the journal', function () {
    $this->actingAs(commercial());
    $org = tlOrg();
    $note = tlNote($org, 'Mme Durand est malade, rappeler');
    $note->delete();

    $dump = json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);

    expect($dump)->not->toContain('Durand')->toContain('[donnée personnelle]');
});

it('records a contact on the entreprise when a note is written', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00'));
    $org = tlOrg();
    expect($org->last_contact_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:30:00'));
    tlNote($org);

    $fresh = $org->fresh();
    expect($fresh->last_contact_at?->toDateTimeString())->toBe('2026-11-12 10:30:00')
        ->and(Timeline::for($fresh)->pluck('title')->all())->toBe(['Note', 'Fiche créée']);
});

it('records a contact on the person and on her entreprises with an active affiliation only', function () {
    $this->actingAs(commercial());
    $person = makePerson('Roux');
    $active = tlOrg('Active');
    $ended = tlOrg('Terminée');
    $other = tlOrg('Sans lien');
    affiliate($person, $active);
    affiliate($person, $ended, ['ended_on' => '2026-01-01']);

    $this->travelTo(CarbonImmutable::parse('2026-11-12 14:00:00'));
    tlNote($person);

    expect($person->fresh()->last_contact_at?->toDateTimeString())->toBe('2026-11-12 14:00:00')
        ->and($active->fresh()->last_contact_at?->toDateTimeString())->toBe('2026-11-12 14:00:00')
        ->and($ended->fresh()->last_contact_at)->toBeNull()
        ->and($other->fresh()->last_contact_at)->toBeNull();
});

it('does not rewrite the fiche modification date nor the journal when a note records a contact', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00'));
    tlNote($org);

    expect($org->fresh()->updated_at?->toDateTimeString())->toBe('2026-11-12 09:00:00')
        ->and(DB::table('audit_logs')->where('auditable_id', $org->id)->where('event', 'updated')->count())->toBe(0);
});

it('pages the feed by time cursor', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    foreach (range(1, 34) as $i) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00')->addMinutes($i));
        tlNote($org, "Note {$i}");
    }

    $first = $this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk();
    expect($first->json('entries'))->toHaveCount(30)
        ->and($first->json('entries.0.body'))->toBe('Note 34')
        ->and($first->json('next'))->not->toBeNull();

    $second = $this->getJson("/crm/organisations/{$org->id}/timeline?before=".urlencode($first->json('next')))->assertOk();
    expect($second->json('entries'))->toHaveCount(5)
        ->and($second->json('entries.0.body'))->toBe('Note 4')
        ->and($second->json('entries.4.title'))->toBe('Fiche créée')
        ->and($second->json('next'))->toBeNull();
});

it('does not skip entries created within the same second at a page boundary', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00.100000'));
    tlNote($org, 'A');
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00.200000'));
    tlNote($org, 'B');

    $entries = Timeline::for($org->fresh(), null, 1);
    $next = Timeline::for($org->fresh(), $entries->last()->at, 1);

    expect($entries->first()->body)->toBe('B')->and($next->first()->body)->toBe('A');
});

it('rejects a malformed cursor', function () {
    $org = tlOrg();
    $this->actingAs(commercial())->getJson("/crm/organisations/{$org->id}/timeline?before=pas-une-date")->assertStatus(422);
});

it('adds a note to an entreprise and to a person, authored by the user', function () {
    $user = commercial();
    $org = tlOrg();
    $person = makePerson('Roux');

    $this->actingAs($user)->post("/crm/organisations/{$org->id}/notes", ['body' => "  Rappeler lundi\nAvec le devis "])->assertRedirect();
    $this->post("/crm/people/{$person->id}/notes", ['body' => 'Souhaite une formation'])->assertRedirect();

    $note = Note::query()->where('notable_id', $org->id)->sole();
    expect($note->body)->toBe("Rappeler lundi\nAvec le devis")
        ->and($note->author_id)->toBe($user->id)
        ->and(Note::query()->where('notable_id', $person->id)->count())->toBe(1);
});

it('validates the note text', function () {
    $org = tlOrg();
    $this->actingAs(commercial())->postJson("/crm/organisations/{$org->id}/notes", ['body' => '   '])->assertJsonValidationErrors('body');
    $this->postJson("/crm/organisations/{$org->id}/notes", ['body' => str_repeat('a', 10001)])->assertJsonValidationErrors('body');
    $this->postJson("/crm/organisations/{$org->id}/notes", ['body' => str_repeat('a', 10000)])->assertRedirect();
    expect(Note::count())->toBe(1);
});

it('forbids notes without crm.update and the feed without crm.view', function () {
    $org = tlOrg();

    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))
        ->post("/crm/organisations/{$org->id}/notes", ['body' => 'x'])->assertForbidden();
    $this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk();
    expect(Note::count())->toBe(0);
});

it('hides the feed, the notes and the attachments of an invisible fiche from a Responsable DAS', function () {
    $das = ReferenceItem::query()->where('list', ReferenceList::Das)->orderBy('position')->firstOrFail();
    $org = tlOrg();
    $note = tlNote($org);
    $attachment = tlAttachment($org);
    $user = userWithRole(Role::ResponsableDas, twoFactor: true);
    DB::table('user_das')->insert(['tenant_id' => tenant()->id, 'user_id' => $user->id, 'das_id' => $das->id]);

    $this->actingAs($user)->getJson("/crm/organisations/{$org->id}/timeline")->assertNotFound();
    $this->get("/crm/attachments/{$attachment->id}")->assertNotFound();

    DB::table('organisation_das')->insert(['tenant_id' => tenant()->id, 'organisation_id' => $org->id, 'das_id' => $das->id]);
    $this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->assertJsonCount(3, 'entries');
});

it('soft-deletes a note and removes it from the feed', function () {
    $this->actingAs(commercial());
    $org = tlOrg();
    $note = tlNote($org);

    $this->delete("/crm/notes/{$note->id}")->assertRedirect();

    expect(Note::count())->toBe(0)->and(Note::withTrashed()->count())->toBe(1)
        ->and(Timeline::for($org->fresh())->pluck('type')->all())->toBe(['audit']);
});

it('refuses notes on a deleted fiche and a note of another organisme', function () {
    $this->actingAs(commercial());
    $org = tlOrg();
    $org->delete();
    $this->post("/crm/organisations/{$org->id}/notes", ['body' => 'x'])->assertNotFound();

    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => tlNote(tlOrg('Autre')));
    $this->delete("/crm/notes/{$foreign->id}")->assertNotFound();
    $this->delete('/crm/notes/pas-un-uuid')->assertNotFound();
});

it('does not bring back, on the next page, audit entries newer than the cursor (timezone)', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00'));
    tlNote($org, 'A');
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:30:00'));
    $org->update(['city' => 'Annecy']);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 11:00:00'));
    tlNote($org, 'B');

    $first = Timeline::for($org->fresh(), null, 3);
    expect($first->pluck('title')->all())->toBe(['Note', 'Fiche modifiée (champs : Ville)', 'Note']);

    $second = Timeline::for($org->fresh(), $first->last()->at, 3);
    expect($second->pluck('title')->all())->toBe(['Fiche créée']);
});

it('stores last_contact_at at the exact instant of the note, in the application timezone', function () {
    $this->actingAs(commercial());
    $frozen = CarbonImmutable::parse('2026-11-12 10:30:00', 'Europe/Paris');
    $this->travelTo($frozen);
    $org = tlOrg();
    tlNote($org);

    $stored = $org->fresh()->last_contact_at;
    expect($stored->equalTo($frozen))->toBeTrue()
        ->and(DB::selectOne("select to_char(last_contact_at at time zone 'Europe/Paris', 'YYYY-MM-DD HH24:MI:SS') as v from organisations where id = ?", [$org->id])->v)
        ->toBe('2026-11-12 10:30:00')
        ->and(DB::selectOne("select current_setting('TimeZone') as v")->v)->toBe('Europe/Paris');
});
