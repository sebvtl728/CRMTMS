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
use App\Modules\Crm\Timeline\TimelineCursor;
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
    // Fixed author name: a random (fr_FR) one could be « … Dupont » or « … Martin ».
    $user = commercial();
    $user->forceFill(['name' => 'Agent Commercial'])->save();
    $this->actingAs($user);
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
    $next = Timeline::for($org->fresh(), $entries->last()->cursor(), 1);

    expect($entries->first()->body)->toBe('B')->and($next->first()->body)->toBe('A');
});

it('falls back to the first page on a malformed cursor', function (string $cursor) {
    $org = tlOrg();
    $this->actingAs(commercial())->getJson("/crm/organisations/{$org->id}/timeline?before=".urlencode($cursor))
        ->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.title', 'Fiche créée')->assertJsonPath('next', null);
})->with([
    'not base64' => 'pas-une-date!',
    'old time cursor' => '2026-11-12T10:00:00.000000Z',
    'not json' => rtrim(strtr(base64_encode('{oops'), '+/', '-_'), '='),
    'json list' => rtrim(strtr(base64_encode('[1,2,3]'), '+/', '-_'), '='),
    'bad date' => rtrim(strtr(base64_encode('{"at":"hier","type":"note","id":"0195b2c4-0000-7000-8000-000000000000"}'), '+/', '-_'), '='),
    'bad id' => rtrim(strtr(base64_encode('{"at":"2026-11-12T10:00:00Z","type":"note","id":"1 or 1=1"}'), '+/', '-_'), '='),
    'bad type' => rtrim(strtr(base64_encode('{"at":"2026-11-12T10:00:00Z","type":["x"],"id":"0195b2c4-0000-7000-8000-000000000000"}'), '+/', '-_'), '='),
]);

it('encodes the cursor as an opaque base64url string that round-trips', function () {
    $cursor = new TimelineCursor(CarbonImmutable::parse('2026-11-12 10:00:00.123456', 'Europe/Paris'), 'note', '0195b2c4-0000-7000-8000-000000000000');
    $encoded = $cursor->encode();
    $decoded = TimelineCursor::decode($encoded);

    expect($encoded)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($decoded?->at->equalTo($cursor->at))->toBeTrue()
        ->and($decoded?->at->format('u'))->toBe('123456')
        ->and($decoded?->type)->toBe('note')
        ->and($decoded?->id)->toBe($cursor->id);
});

it('loses and repeats no entry of two providers at the same instant across a page boundary', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:30:00.250000'));
    tlNote($org, 'Même instant');
    tlAttachment($org, 'meme-instant.pdf');
    tlNote($org, 'Même instant bis');

    $seen = [];
    $cursor = null;
    do {
        $page = Timeline::for($org->fresh(), $cursor, 1);
        foreach ($page as $entry) {
            $seen[] = $entry->type.':'.($entry->meta['id'] ?? '');
        }
        $cursor = $page->last()?->cursor();
    } while ($page->isNotEmpty() && count($seen) < 10);

    $all = Timeline::for($org->fresh());
    expect($seen)->toHaveCount(4)->toBe(array_values(array_unique($seen)))
        ->and($seen)->toBe($all->map(fn ($e) => $e->type.':'.$e->meta['id'])->all())
        // Stable order: at desc, type desc, id desc.
        ->and($all->pluck('type')->all())->toBe(['note', 'note', 'attachment', 'audit']);
});

it('keeps over HTTP an entry of another provider at the instant of the last entry of a page', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00'));
    $org = tlOrg();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:30:00'));
    tlNote($org, 'Note limite');
    tlAttachment($org, 'limite.pdf');
    foreach (range(1, 29) as $i) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00')->addMinutes($i));
        tlNote($org, "Note {$i}");
    }

    $first = $this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk();
    expect($first->json('entries'))->toHaveCount(30)
        ->and($first->json('entries.29.body'))->toBe('Note limite')
        ->and($first->json('next'))->toBeString()->not->toContain('2026');

    $second = $this->getJson("/crm/organisations/{$org->id}/timeline?before=".urlencode($first->json('next')))->assertOk();
    expect(array_column($second->json('entries'), 'title'))->toBe(['limite.pdf', 'Fiche créée'])
        ->and($second->json('next'))->toBeNull();
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

it('forbids notes without crm.fiches.update and the feed without crm.fiches.view', function () {
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

    $second = Timeline::for($org->fresh(), $first->last()->cursor(), 3);
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

it('pages across the autumn dst fold without skipping nor repeating an entry', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00'));
    $org = tlOrg();
    // 02:30 twice in Paris on 25/10/2026: CEST (00:30 UTC) then CET (01:30 UTC).
    $cet = tlNote($org, 'CET');
    $cest = tlNote($org, 'CEST');
    DB::table('notes')->where('id', $cest->id)->update(['created_at' => DB::raw("'2026-10-25 00:30:00+00'::timestamptz")]);
    DB::table('notes')->where('id', $cet->id)->update(['created_at' => DB::raw("'2026-10-25 01:30:00+00'::timestamptz")]);

    $seen = [];
    $cursor = null;
    for ($i = 0; $i < 5; $i++) {
        $page = Timeline::for($org->fresh(), $cursor, 1);
        if ($page->isEmpty()) {
            break;
        }
        $seen[] = $page->first()->body ?? $page->first()->type;
        $cursor = $page->last()->cursor();
    }

    expect(array_slice($seen, 0, 2))->toBe(['CET', 'CEST'])->and($seen)->toHaveCount(3);
});
