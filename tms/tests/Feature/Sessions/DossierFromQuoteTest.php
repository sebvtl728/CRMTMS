<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions as CrmPermissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierFromQuote;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentOptions;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingRows;
use App\Modules\Sessions\Fundings\FundingStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

function dfqTraining(string $title): Training
{
    return Training::query()->where('title', $title)->first()
        ?? Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '14.00']);
}

/**
 * An accepted quote of $account: HACCP 3 stagiaires × 400 €, Hygiène 1
 * forfait × 1 500 €, a travel fee of 120 € (2 820 € HT).
 */
function dfqQuote(Organisation|Person $account, ?Person $contact = null): Quote
{
    $line = fn (?string $training, string $label, string $quantity, string $unit, string $price) => [
        'training_id' => $training, 'label' => $label, 'description' => null, 'quantity' => $quantity,
        'unit' => $unit, 'unit_price' => $price, 'discount_percent' => '0.00', 'vat_rate' => '20.00',
    ];
    $quote = makeQuote(makeOpportunity($account), Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create(), [
        $line(dfqTraining('HACCP')->id, 'HACCP', '3.00', 'stagiaire', '400.00'),
        $line(dfqTraining('Hygiène')->id, 'Hygiène', '1.00', 'forfait', '1500.00'),
        $line(null, 'Frais de déplacement', '1.00', 'forfait', '120.00'),
    ]);
    $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => '2026-11-16', 'contact_person_id' => $contact?->id])->save();

    return $quote;
}

function dfqInter(string $title = 'HACCP', int $capacity = 10): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => dfqTraining($title)->id, 'kind' => 'inter', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02',
        'location_kind' => 'remote', 'capacity' => $capacity, 'status' => 'planned',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dfqNewSession(array $overrides = []): array
{
    return [
        'kind' => 'intra', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-08', 'modality_id' => SessionWriter::defaultModalityId(),
        'location_kind' => 'client', 'location_text' => null, 'capacity' => null, ...$overrides,
    ];
}

it('creates the dossier, its sessions, its trainees and its funding from an accepted quote', function () {
    $admin = admin(true);
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $contact = makePerson('Martin', ['first_name' => 'Léa']);
    $quote = dfqQuote($org, $contact);
    $inter = dfqInter();
    $p1 = makePerson('Durand', ['first_name' => 'Paul']);
    $p2 = makePerson('Petit', ['first_name' => 'Anne']);

    $response = $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['session_id' => $inter->id, 'pricing_mode' => 'per_trainee', 'trainees' => [$p1->id, ['person_id' => $p2->id]]],
        ['new_session' => dfqNewSession(), 'pricing_mode' => 'group', 'trainees' => [
            ['new_person' => ['first_name' => 'Zoé', 'last_name' => 'Nouvelle', 'email' => 'zoe.nouvelle@example.test', 'employee' => true]],
        ]],
        [],
    ]]);

    $response->assertCreated();
    $dossier = Dossier::query()->sole();
    $response->assertJson(['url' => route('dossiers.show', $dossier)]);
    expect($dossier->quote_id)->toBe($quote->id)
        ->and($dossier->opportunity_id)->toBe($quote->opportunity_id)
        ->and($dossier->account_organisation_id)->toBe($org->id)
        ->and($dossier->account_person_id)->toBeNull()
        ->and($dossier->contact_person_id)->toBe($contact->id)
        ->and($dossier->company_id)->toBe($quote->company_id)
        ->and($dossier->das_id)->toBe($quote->opportunity->das_id)
        ->and($dossier->responsible_user_id)->toBe($admin->id)
        ->and($dossier->number)->toBe('D-2026-0001');

    $lines = $dossier->lines()->get();
    expect($lines->pluck('kind')->all())->toBe(['training', 'training', 'fee'])
        ->and($lines->pluck('training_id')->all())->toBe([dfqTraining('HACCP')->id, dfqTraining('Hygiène')->id, null])
        ->and($lines->pluck('pricing_mode')->all())->toBe(['per_trainee', 'group', null])
        ->and($lines->pluck('total_ht')->all())->toBe(['1200.00', '1500.00', '120.00'])
        ->and($dossier->load('lines')->priceCents())->toBe(282000);

    // The new intra session of the entreprise.
    $new = TrainingSession::query()->whereKeyNot($inter->id)->sole();
    expect($new->kind)->toBe('intra')
        ->and($new->organisation_id)->toBe($org->id)
        ->and($new->training_id)->toBe(dfqTraining('Hygiène')->id)
        ->and($new->starts_on->toDateString())->toBe('2026-12-07');

    // Three trainees, one created as an ordinary person (employee, the training's DAS).
    $zoe = Person::query()->where('last_name', 'Nouvelle')->sole();
    expect(Enrolment::query()->where('dossier_id', $dossier->id)->orderBy('created_at')->get()->map(fn (Enrolment $e) => [$e->training_session_id, $e->person_id, $e->dossier_line_id])->all())
        ->toEqualCanonicalizing([
            [$inter->id, $p1->id, $lines[0]->id],
            [$inter->id, $p2->id, $lines[0]->id],
            [$new->id, $zoe->id, $lines[1]->id],
        ])
        ->and(Affiliation::query()->where('person_id', $zoe->id)->value('organisation_id'))->toBe($org->id)
        ->and($zoe->das()->pluck('reference_items.id')->all())->toBe([dfqTraining('Hygiène')->das_id])
        // Per trainee: 1 200 € shared by the two trainees.
        ->and(Enrolment::query()->where('dossier_line_id', $lines[0]->id)->pluck('price_share')->all())->toBe(['600.00', '600.00']);

    // The entreprise funds the whole price, to request.
    $funding = Funding::query()->sole();
    expect($funding->dossier_id)->toBe($dossier->id)
        ->and($funding->funder_organisation_id)->toBe($org->id)
        ->and($funding->funder_person_id)->toBeNull()
        ->and($funding->funding_type_id)->toBe(crmItem(ReferenceList::FundingType, 'Entreprise')->id)
        ->and($funding->amount)->toBe('2820.00')
        ->and($funding->status)->toBe(FundingStatus::ToRequest)
        ->and($funding->dossier_line_id)->toBeNull()
        ->and($funding->enrolment_id)->toBeNull()
        ->and(FundingRows::balance($dossier->fresh())['remaining_cents'])->toBe(0);
});

it('funds a particulier\'s dossier as « Personnel » and plans him an individual session', function () {
    $person = makePerson('Durand', ['first_name' => 'Paul', 'is_individual' => true]);
    $quote = dfqQuote($person);

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['new_session' => dfqNewSession(['kind' => 'individual']), 'trainees' => [$person->id]],
        [],
        [],
    ]])->assertCreated();

    $dossier = Dossier::query()->sole();
    $session = TrainingSession::query()->sole();
    expect($dossier->account_person_id)->toBe($person->id)
        ->and($session->kind)->toBe('individual')
        ->and($session->organisation_id)->toBeNull()
        ->and(Enrolment::query()->sole()->person_id)->toBe($person->id)
        // Pricing mode proposed from the unit: « stagiaire » → per trainee, « forfait » → group.
        ->and($dossier->lines()->pluck('pricing_mode')->all())->toBe(['per_trainee', 'group', null]);
    $funding = Funding::query()->sole();
    expect($funding->funder_person_id)->toBe($person->id)
        ->and($funding->funding_type_id)->toBe(crmItem(ReferenceList::FundingType, 'Personnel')->id)
        ->and($funding->amount)->toBe('2820.00');
});

it('funds a new dossier with the funding type flagged for its contractant, or not at all', function () {
    $opco = crmItem(ReferenceList::FundingType, 'OPCO');
    $opco->update(['meta' => ['default_for' => 'organisation']]);
    crmItem(ReferenceList::FundingType, 'Entreprise')->update(['meta' => null]);
    crmItem(ReferenceList::FundingType, 'Personnel')->update(['meta' => null]);
    $admin = admin(true);

    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']))->id), ['lines' => [[], [], []]])->assertCreated();
    expect(Funding::query()->sole()->funding_type_id)->toBe($opco->id);

    // No type flagged for a particulier: his dossier starts without funding.
    $person = makePerson('Durand', ['first_name' => 'Paul', 'is_individual' => true]);
    $this->postJson(route('dossiers.from-quote.store', dfqQuote($person)->id), ['lines' => [[], [], []]])->assertCreated();
    expect(Dossier::query()->where('account_person_id', $person->id)->sole()->fundings()->count())->toBe(0);
});

it('plans a new session in the premises of one of our companies', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $company = Company::query()->firstWhere('is_default', true) ?? Company::query()->create(['name' => 'CAP FORMATION', 'vat_regime' => 'exempt']);

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['new_session' => dfqNewSession(['location_kind' => 'company', 'location_company_id' => $company->id])], [], [],
    ]])->assertSuccessful();

    expect(TrainingSession::query()->sole()->location_company_id)->toBe($company->id);
});

it('asks which company for a new session in our premises', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['new_session' => dfqNewSession(['location_kind' => 'company'])], [], [],
    ]])->assertUnprocessable()->assertJsonPath('errors', fn ($e) => ($e['lines.0.new_session.location_company_id'][0] ?? null) === 'Choisissez la société.');
});

it('refuses an intra session to a particulier', function () {
    $quote = dfqQuote(makePerson('Durand', ['is_individual' => true]));

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['new_session' => dfqNewSession()], [], [],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.new_session.kind']);

    expect(Dossier::query()->count())->toBe(0);
});

it('creates one dossier per quote: a second attempt is a 409 with a link (Review Focus 4)', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $admin = admin(true);

    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [[], [], []]])->assertCreated();
    $dossier = Dossier::query()->sole();

    // Double click, second tab.
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [[], [], []]])
        ->assertStatus(409)
        ->assertJson(['message' => 'Un dossier existe déjà pour ce devis.', 'dossier' => ['number' => $dossier->number, 'url' => route('dossiers.show', $dossier)]]);

    // The service itself, and even with the dossier in the trash (unique quote_id).
    $dossier->delete();
    expect(fn () => app(DossierFromQuote::class)->create($quote->id, ['lines' => [[], [], []]], $admin))
        ->toThrow(UserFacingHttpException::class, 'Un dossier existe déjà pour ce devis.');
    expect(Dossier::withTrashed()->count())->toBe(1)
        ->and(Funding::query()->count())->toBe(1);

    // The assistant page says so with the link.
    $dossier->restore();
    $this->actingAs($admin)->get(route('dossiers.from-quote', $quote->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sessions/dossiers/from-quote')
            ->where('existing.number', $dossier->number)
            ->where('existing.url', route('dossiers.show', $dossier)));
});

it('answers 404 for a quote not accepted, unknown or out of sight', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $sent = makeQuote(makeOpportunity($org));
    $sent->forceFill(['status' => QuoteStatus::Sent])->save();
    $admin = admin(true);

    $this->actingAs($admin)->get(route('dossiers.from-quote', $sent->id))->assertNotFound();
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $sent->id), ['lines' => [[]]])->assertNotFound();
    $this->actingAs($admin)->get(route('dossiers.from-quote', (string) Str::uuid7()))->assertNotFound();
    $this->actingAs($admin)->get(route('dossiers.from-quote', 'nope'))->assertNotFound();

    // Dossiers right but no right on the quotes.
    $accepted = dfqQuote($org);
    $blind = userWithPermissions([Permissions::DOSSIERS_VIEW, Permissions::DOSSIERS_UPDATE]);
    $this->actingAs($blind)->get(route('dossiers.from-quote', $accepted->id))->assertNotFound();
    expect(Dossier::query()->count())->toBe(0);
});

it('answers 403 without sessions.dossiers.update', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $reader = userWithPermissions([CrmPermissions::QUOTES_VIEW, Permissions::DOSSIERS_VIEW, Permissions::SESSIONS_UPDATE]);

    $this->actingAs($reader)->get(route('dossiers.from-quote', $quote->id))->assertForbidden();
    $this->actingAs($reader)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [[], [], []]])->assertForbidden();
    expect(Dossier::query()->count())->toBe(0);
});

it('needs sessions.sessions.update to plan sessions or enrol trainees', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $user = userWithPermissions([CrmPermissions::QUOTES_VIEW, Permissions::DOSSIERS_VIEW, Permissions::DOSSIERS_UPDATE]);

    $this->actingAs($user)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [['new_session' => dfqNewSession()], [], []]])
        ->assertForbidden();
    expect(Dossier::query()->count())->toBe(0);

    // The dossier alone: sessions and trainees later.
    $this->actingAs($user)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [[], [], []]])->assertCreated();
    expect(Dossier::query()->count())->toBe(1)->and(TrainingSession::query()->count())->toBe(0);
});

it('creates nothing when a part of the plan is invalid', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $quote = dfqQuote($org);
    $inter = dfqInter();
    $other = Organisation::query()->create(['name' => 'Autre hôtel']);
    $foreignIntra = app(SessionWriter::class)->create([
        'training_id' => dfqTraining('Hygiène')->id, 'kind' => 'intra', 'organisation_id' => $other->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01',
        'location_kind' => 'client', 'status' => 'planned',
    ]);
    $admin = admin(true);
    $count = fn () => [Dossier::withTrashed()->count(), TrainingSession::query()->count(), Person::query()->count(), Funding::query()->count(), Enrolment::query()->count()];
    $before = $count();

    // A new session ending before it starts, after a trainee to create.
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['session_id' => $inter->id, 'trainees' => [['new_person' => ['last_name' => 'Nouvelle']]]],
        ['new_session' => dfqNewSession(['ends_on' => '2026-12-01']), 'trainees' => []],
        [],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.new_session.ends_on']);
    expect($count())->toBe($before);

    // Trainees without a session; a session of another training; another entreprise's intra session; unknown pricing mode.
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['trainees' => [makePerson('Seul')->id], 'pricing_mode' => 'nope'],
        ['session_id' => $inter->id],
        [],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.trainees', 'lines.0.pricing_mode', 'lines.1.session_id']);
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        [], ['session_id' => $foreignIntra->id, 'trainees' => [makePerson('Seul')->id]], [],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.session_id']);

    // The same trainee twice on a session.
    $p = makePerson('Durand');
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['session_id' => $inter->id, 'trainees' => [$p->id, $p->id]], [], [],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.trainees.1']);

    // A fee line takes no session.
    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        [], [], ['session_id' => $inter->id],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.2.session_id']);
    expect(Dossier::withTrashed()->count())->toBe(0);
});

it('asks before creating a trainee who resembles an existing person', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $inter = dfqInter();
    makePerson('Durand', ['first_name' => 'Paul', 'email' => 'paul.durand@example.test']);
    $plan = fn (bool $confirm) => ['lines' => [
        ['session_id' => $inter->id, 'trainees' => [['new_person' => ['first_name' => 'Paul', 'last_name' => 'Durand', 'email' => 'paul.durand@example.test', 'confirm_duplicate' => $confirm]]]],
        [], [],
    ]];

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), $plan(false))
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.trainees.0']);
    expect(Dossier::query()->count())->toBe(0);

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), $plan(true))->assertCreated();
    expect(Person::query()->where('last_name', 'Durand')->count())->toBe(2);
});

it('prepares the summary and the sessions open to each training line', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $quote = dfqQuote($org, makePerson('Martin', ['first_name' => 'Léa']));
    $inter = dfqInter();
    $session = fn (array $data) => app(SessionWriter::class)->create([
        'training_id' => dfqTraining('Hygiène')->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01', 'location_kind' => 'client', 'status' => 'planned', ...$data,
    ]);
    $ownIntra = $session(['kind' => 'intra', 'organisation_id' => $org->id]);
    $session(['kind' => 'intra', 'organisation_id' => Organisation::query()->create(['name' => 'Autre'])->id]);
    $freeIndividual = $session(['kind' => 'individual']);
    // An individual session of the entreprise already taken: never offered.
    $taken = $session(['kind' => 'individual', 'organisation_id' => $org->id]);
    $other = dfqDossier($org);
    app(Enrolments::class)->enrol($other, $other->lines()->firstOrFail(), $taken, makePerson('Pris')->id);
    $cancelled = dfqInter('Hygiène');
    app(SessionWriter::class)->cancel($cancelled, crmItem(ReferenceList::CancelReason, 'Report')->id);

    $plan = app(DossierFromQuote::class)->prepare($quote->id, admin(true));

    expect($plan['quote']['number'])->toBe($quote->number)
        ->and($plan['quote']['account'])->toBe(['type' => 'organisation', 'id' => $org->id, 'label' => 'Hôtel du Parc'])
        ->and($plan['quote']['contact']['label'])->toBe('Léa Martin')
        ->and($plan['quote']['total_ht'])->toBe('2 820,00 €')
        ->and(array_column($plan['lines'], 'kind'))->toBe(['training', 'training', 'fee'])
        ->and(array_column($plan['lines'], 'pricing_mode'))->toBe(['per_trainee', 'group', null])
        ->and(array_column($plan['lines'][0]['sessions'], 'id'))->toBe([$inter->id])
        ->and(array_column($plan['lines'][1]['sessions'], 'id'))->toEqualCanonicalizing([$ownIntra->id, $freeIndividual->id])
        ->and($plan['lines'][2]['sessions'])->toBe([]);
});

it('never offers a full individual session of the contractant in the enrolment options', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $taken = app(SessionWriter::class)->create([
        'training_id' => dfqTraining('Hygiène')->id, 'kind' => 'individual', 'organisation_id' => $org->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01',
        'location_kind' => 'client', 'status' => 'planned',
    ]);
    $dossier = dfqDossier($org);
    expect(array_column(EnrolmentOptions::sessionsFor($dossier->load('lines'), admin(true))[$dossier->lines[0]->id], 'id'))->toBe([$taken->id]);

    app(Enrolments::class)->enrol($dossier, $dossier->lines[0], $taken, makePerson('Pris')->id);

    expect(EnrolmentOptions::sessionsFor($dossier->fresh()->load('lines'), admin(true))[$dossier->lines[0]->id])->toBe([]);
});

it('shows « Créer le dossier », then « Voir le dossier » on the accepted quote', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $admin = admin(true);

    $this->actingAs($admin)->get(route('crm.quotes.show', $quote))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('links', [['key' => 'sessions.dossier', 'label' => 'Créer le dossier', 'href' => route('dossiers.from-quote', $quote->id)]]));

    // Without the right to create a dossier: nothing.
    $seller = userWithPermissions([CrmPermissions::QUOTES_VIEW, Permissions::DOSSIERS_VIEW]);
    $this->actingAs($seller)->get(route('crm.quotes.show', $quote))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('links', []));

    $this->actingAs($admin)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [[], [], []]])->assertCreated();
    $dossier = Dossier::query()->sole();

    foreach ([$admin, $seller] as $user) {
        $this->actingAs($user)->get(route('crm.quotes.show', $quote))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links', [['key' => 'sessions.dossier', 'label' => "Voir le dossier {$dossier->number}", 'href' => route('dossiers.show', $dossier)]]));
    }

    // A quote not accepted: no link.
    $draft = makeQuote($quote->opportunity);
    $this->actingAs($admin)->get(route('crm.quotes.show', $draft))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('links', []));
});

/**
 * A dossier of $org with one « Hygiène » training line.
 */
function dfqDossier(Organisation $org): Dossier
{
    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => $org->id,
        'das_id' => dfqTraining('Hygiène')->das_id,
        'lines' => [['kind' => DossierLine::TRAINING, 'training_id' => dfqTraining('Hygiène')->id, 'label' => 'Hygiène', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '900', 'discount_percent' => '']],
    ]);
}

it('creates once a new trainee listed on two lines (same ref)', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $quote = dfqQuote($org);
    $inter = dfqInter();
    $zoe = ['ref' => 'n1', 'first_name' => 'Zoé', 'last_name' => 'Nouvelle', 'email' => 'zoe.nouvelle@example.test', 'employee' => true];

    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [
        ['session_id' => $inter->id, 'trainees' => [['new_person' => $zoe]]],
        ['new_session' => dfqNewSession(), 'trainees' => [['new_person' => $zoe]]],
        [],
    ]])->assertCreated();

    $person = Person::query()->where('last_name', 'Nouvelle')->sole();
    expect(Enrolment::query()->where('person_id', $person->id)->count())->toBe(2)
        ->and(Affiliation::query()->where('person_id', $person->id)->count())->toBe(1);

    // Two people of the same name without the same ref: the second one is a possible duplicate.
    $other = dfqQuote($org);
    $this->actingAs(admin(true))->postJson(route('dossiers.from-quote.store', $other->id), ['lines' => [
        ['session_id' => $inter->id, 'trainees' => [['new_person' => ['ref' => 'a', 'last_name' => 'Martin', 'first_name' => 'Jo', 'email' => 'jo@example.test']]]],
        ['new_session' => dfqNewSession(), 'trainees' => [['new_person' => ['ref' => 'b', 'last_name' => 'Martin', 'first_name' => 'Jo', 'email' => 'jo@example.test']]]],
        [],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.trainees.0']);
});

it('refuses the assistant when the quote\'s DAS is outside « Ses DAS » for the dossiers', function () {
    $quote = dfqQuote(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $user = userWithPermissions([CrmPermissions::QUOTES_VIEW, Permissions::DOSSIERS_VIEW, Permissions::DOSSIERS_UPDATE]);
    DB::table(Scopes::TABLE)->insert([
        'tenant_id' => tenant()->id, 'role_id' => $user->roles()->firstOrFail()->id,
        'permission' => Permissions::DOSSIERS_UPDATE, 'scope' => AccessScope::Das->value,
    ]);
    Scopes::forget();

    $this->actingAs($user)->get(route('dossiers.from-quote', $quote->id))->assertForbidden();
    $this->actingAs($user)->postJson(route('dossiers.from-quote.store', $quote->id), ['lines' => [[], [], []]])->assertForbidden();
    $this->actingAs($user)->get(route('crm.quotes.show', $quote))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('links', []));
});
