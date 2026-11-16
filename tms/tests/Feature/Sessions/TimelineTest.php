<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions as CrmPermissions;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
    $this->actingAs(admin(twoFactor: true));
});

/**
 * Titles (and bodies) of the entries of $type in the history of a fiche, all pages.
 *
 * @return list<string>
 */
function l2Feed(string $url, string $type): array
{
    $titles = [];
    $next = null;
    do {
        $page = test()->getJson($next === null ? $url : $url.'?before='.urlencode($next))->assertOk();
        foreach ($page->json('entries') as $entry) {
            if ($entry['type'] === $type) {
                $titles[] = $entry['title'].($entry['body'] !== null ? ' | '.$entry['body'] : '');
            }
        }
        $next = $page->json('next');
    } while ($next !== null);

    return $titles;
}

it('shows on the person\'s history her enrolments: enrolment, waiting list, confirmation, abandon, cancellation', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $full = l2Session(null, ['capacity' => 1]);
    $other = l2Session(null, ['starts_on' => '2027-01-11', 'ends_on' => '2027-01-13']);
    $dossier = l2Dossier();
    l2Enrol($dossier, $full, makePerson('Martin'));
    $enrolments = app(Enrolments::class);

    $first = l2Enrol($dossier, $other, $lea);
    $this->travel(1)->minutes();
    $enrolments->confirm($first);
    $this->travel(1)->minutes();
    $enrolments->abandon($first->refresh(), CarbonImmutable::parse('2027-01-12'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    $this->travel(1)->minutes();
    $waiting = l2Enrol($dossier, $full, $lea);
    $this->travel(1)->minutes();
    $enrolments->cancel($waiting);

    expect(l2Feed("/crm/people/{$lea->id}/timeline", 'enrolment'))->toBe([
        "Inscription annulée — session {$full->number} (HACCP 3 jours)",
        "Inscription en liste d'attente à la session {$full->number} (HACCP 3 jours)",
        "Abandon — session {$other->number} (HACCP 3 jours) | Sortie le 12/01/2027 · Motif : Maladie",
        "Inscription confirmée — session {$other->number} (HACCP 3 jours)",
        "Inscription à la session {$other->number} (HACCP 3 jours)",
    ]);
    $entry = collect($this->getJson("/crm/people/{$lea->id}/timeline")->json('entries'))->firstWhere('type', 'enrolment');
    expect($entry['meta']['href'])->toBe("/sessions/{$full->id}")->and($entry['author'])->not->toBeNull();
});

it('shows the dossiers of an entreprise and of their contact: creation and states, with the reason of a cancellation', function () {
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $dossier = l2Dossier($hotel);
    $dossier->forceFill(['contact_person_id' => $lea->id])->save();
    $transitions = app(DossierTransitions::class);
    $this->travel(1)->minutes();
    $transitions->sendConvention($dossier, CarbonImmutable::parse('2026-11-16'));
    $this->travel(1)->minutes();
    $transitions->cancel($dossier->refresh(), crmItem(ReferenceList::CancelReason, 'Demande du client')->id);

    $expected = [
        "Dossier {$dossier->number} : Annulé | Motif : Demande du client",
        "Dossier {$dossier->number} : Convention envoyée",
        "Dossier {$dossier->number} créé",
    ];
    expect(l2Feed("/crm/organisations/{$hotel->id}/timeline", 'dossier'))->toBe($expected)
        ->and(l2Feed("/crm/people/{$lea->id}/timeline", 'dossier'))->toBe($expected);
    $entry = collect($this->getJson("/crm/organisations/{$hotel->id}/timeline")->json('entries'))->firstWhere('type', 'dossier');
    expect($entry['meta']['href'])->toBe("/dossiers/{$dossier->id}");
});

it('shows the dossiers and enrolments of a fiche within the viewer\'s scopes only', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'is_individual' => true]);
    $dossier = l2Dossier($lea);
    l2Enrol($dossier, l2Session(), $lea);
    $otherDas = crmItem(ReferenceList::Das, 'Métiers supports');
    $crm = [CrmPermissions::FICHES_VIEW => null];

    $this->actingAs(l2User($crm));
    expect(l2Feed("/crm/people/{$lea->id}/timeline", 'dossier'))->toBe([])
        ->and(l2Feed("/crm/people/{$lea->id}/timeline", 'enrolment'))->toBe([]);

    $das = l2User([...$crm, Permissions::DOSSIERS_VIEW => AccessScope::Das, Permissions::SESSIONS_VIEW => AccessScope::Das]);
    followDas($das, $otherDas);
    $this->actingAs($das);
    expect(l2Feed("/crm/people/{$lea->id}/timeline", 'dossier'))->toBe([])
        ->and(l2Feed("/crm/people/{$lea->id}/timeline", 'enrolment'))->toBe([]);

    $this->actingAs(l2User([...$crm, Permissions::DOSSIERS_VIEW => null, Permissions::SESSIONS_VIEW => null]));
    expect(l2Feed("/crm/people/{$lea->id}/timeline", 'dossier'))->toHaveCount(1)
        ->and(l2Feed("/crm/people/{$lea->id}/timeline", 'enrolment'))->toHaveCount(1);
});

it('pages the enrolment entries with the others without losing nor repeating any', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $session = l2Session();
    $dossier = l2Dossier();
    $enrolments = app(Enrolments::class);
    for ($i = 0; $i < 20; $i++) {
        $enrolments->cancel(l2Enrol($dossier, $session, $lea));
    }

    $entries = l2Feed("/crm/people/{$lea->id}/timeline", 'enrolment');
    expect($entries)->toHaveCount(40)
        ->and(collect($entries)->filter(fn (string $t) => str_starts_with($t, 'Inscription annulée'))->count())->toBe(20);
});

it('shows on the dossier page its creation, states, enrolments and fundings', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $dossier = l2Dossier();
    $session = l2Session();
    $this->travel(1)->minutes();
    app(DossierTransitions::class)->sendConvention($dossier, CarbonImmutable::parse('2026-11-16'));
    $this->travel(1)->minutes();
    $enrolment = l2Enrol($dossier, $session, $lea);
    $this->travel(1)->minutes();
    $funding = l2Funding($dossier);
    $this->travel(1)->minutes();
    app(FundingWriter::class)->update($funding, ['status' => 'agreed', 'agreed_on' => '2026-11-16']);
    $this->travel(1)->minutes();
    app(FundingWriter::class)->update($funding->refresh(), ['amount' => '700,00']);
    $this->travel(1)->minutes();
    app(FundingWriter::class)->delete($funding->refresh());
    $this->travel(1)->minutes();
    app(Enrolments::class)->abandon($enrolment->refresh(), CarbonImmutable::parse('2026-12-08'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);

    $this->get("/dossiers/{$dossier->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('history', fn ($history) => collect($history)->map(fn (array $e) => $e['title'].($e['body'] !== null ? ' | '.$e['body'] : ''))->all() === [
            "Léa Durand — Abandon — session {$session->number} (HACCP 3 jours) | Sortie le 08/12/2026 · Motif : Maladie",
            'Financement supprimé : OPCO EP',
            'Financement OPCO EP modifié (montant : 700,00 €)',
            'Financement OPCO EP : Accordé',
            'Financement ajouté : OPCO EP (OPCO), 600,00 €',
            "Léa Durand — Inscription à la session {$session->number} (HACCP 3 jours)",
            'État du dossier : Convention envoyée',
            'Dossier créé',
        ]));
});
