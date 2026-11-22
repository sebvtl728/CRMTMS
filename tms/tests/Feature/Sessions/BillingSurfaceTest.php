<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Public\Billing;
use App\Modules\Sessions\Public\DossierBlocks;
use App\Modules\Sessions\Public\DossierStates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * A dossier of 5 150 € HT for the « Hôtel du Parc »:
 *  - line 1, per trainee (2 × 1 000 €): Léa and Tom on S1 (Sept–Nov),
 *    module A in séances (21 h planned), module B in interval (10 h);
 *    Léa: present 2/09 (180), absent 3/09 (180 missing), partial 5/10
 *    (120 of 180), 300 + 100 min of interval hours; Tom: excused 2/09,
 *    present 3/09 (180), left on 1/10 (Démission);
 *  - line 2, for the group (3 000 €): Marc on S2 (Oct–Nov), séances
 *    12/10 and 13/10 (180 each, no attendance), 14/10 cancelled, 30/11
 *    future; 240 min of interval hours in October;
 *  - line 3, a fee (150 €);
 *  - OPCO agreed, paid directly, 1 200 €; CPF (billed outside the TMS)
 *    requested, 500 €, on Léa.
 * September closed on S1, October on S2. Override: prorata + 30 % dédit.
 *
 * @return array<string, mixed>
 */
function bsSeed(): array
{
    $haccp = l2Training('HACCP 3 jours');
    $sst = l2Training('Sauveteur secouriste du travail');
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $dossier = app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => $hotel->id,
        'account_person_id' => null,
        'das_id' => $haccp->das_id,
        'abandon_mode' => 'prorata_dedit',
        'abandon_dedit_percent' => 30,
        'lines' => [
            ['kind' => 'training', 'training_id' => $haccp->id, 'label' => 'HACCP', 'quantity' => '2', 'unit' => 'stagiaire', 'unit_price' => '1000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee'],
            ['kind' => 'training', 'training_id' => $sst->id, 'label' => 'SST', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '3000', 'discount_percent' => '', 'pricing_mode' => 'group'],
            ['kind' => 'fee', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '150', 'discount_percent' => ''],
        ],
    ]);
    [$perTrainee, $group, $fee] = $dossier->lines()->get()->all();

    $s1 = l2Session($haccp, ['starts_on' => '2026-09-01', 'ends_on' => '2026-11-30']);
    $a = plModule($s1);
    $b = atIntervalModule($s1, 600, 'Autonomie', 2);
    $enrolments = app(Enrolments::class);
    $lea = $enrolments->enrol($dossier, $perTrainee, $s1, makePerson('Durand', ['first_name' => 'Léa'])->id);
    $tom = $enrolments->enrol($dossier, $perTrainee, $s1, makePerson('Petit', ['first_name' => 'Tom'])->id);
    $attendances = app(Attendances::class);
    $hours = app(MonthlyHours::class);
    $sept2 = plSeance($a, '2026-09-02', '09:00', '12:00');
    $sept3 = plSeance($a, '2026-09-03', '09:00', '12:00');
    $oct5 = plSeance($a, '2026-10-05', '09:00', '12:00');
    $cancelled = plSeance($a, '2026-10-06', '09:00', '12:00');
    plSeance($a, '2026-11-20', '09:00', '12:00');
    $attendances->record($sept2, $lea->id, 'present', null, null);
    $attendances->record($sept3, $lea->id, 'absent', null, null);
    $attendances->record($oct5, $lea->id, 'partial', 120, null);
    $attendances->record($cancelled, $lea->id, 'absent', null, null);
    app(Seances::class)->cancel($cancelled, 'Grève');
    $attendances->record($sept2, $tom->id, 'excused', null, null);
    $attendances->record($sept3, $tom->id, 'present', null, null);
    $hours->record($lea->id, $b, '2026-09', 300, null);
    $hours->record($lea->id, $b, '2026-10', 100, null);
    $enrolments->abandon($tom, CarbonImmutable::parse('2026-10-01'), crmItem(ReferenceList::AbandonReason, 'Démission')->id);

    $s2 = l2Session($sst, ['starts_on' => '2026-10-01', 'ends_on' => '2026-11-30', 'kind' => 'intra', 'organisation_id' => $hotel->id]);
    $c = plModule($s2);
    $d = atIntervalModule($s2, 600, 'Pratique', 2);
    $marc = $enrolments->enrol($dossier, $group, $s2, makePerson('Bernard', ['first_name' => 'Marc'])->id);
    plSeance($c, '2026-10-12', '09:00', '12:00');
    plSeance($c, '2026-10-13', '09:00', '12:00');
    app(Seances::class)->cancel(plSeance($c, '2026-10-14', '09:00', '12:00'), 'Formateur malade');
    plSeance($c, '2026-11-30', '09:00', '12:00');
    $hours->record($marc->id, $d, '2026-10', 240, null);

    app(Closures::class)->close($s1, '2026-09');
    app(Closures::class)->close($s2, '2026-10');

    $cpf = crmItem(ReferenceList::FundingType, 'CPF');
    $cpf->meta = [...($cpf->meta ?? []), 'external_billing' => true];
    $cpf->save();
    $opco = l2Funding($dossier, ['amount' => '1 200,00', 'status' => 'agreed', 'agreement_number' => 'PEC-2026-42', 'agreed_on' => '2026-09-01', 'pays_directly' => true]);
    $cpfFunding = l2Funding($dossier, [
        'funder_organisation_id' => Organisation::query()->create(['name' => 'Caisse des dépôts'])->id,
        'funding_type_id' => $cpf->id, 'amount' => '500', 'status' => 'requested', 'target' => 'enrolment', 'enrolment_id' => $lea->id,
    ]);

    return compact('dossier', 'hotel', 'perTrainee', 'group', 'fee', 's1', 's2', 'lea', 'tom', 'marc', 'opco', 'cpfFunding', 'cpf');
}

it('describes a dossier for invoicing: lines, enrolments, fundings and sessions in cents', function () {
    $x = bsSeed();
    $dossier = $x['dossier']->fresh();

    $data = Billing::dossier($dossier->id);

    expect($data)->toMatchArray([
        'id' => $dossier->id,
        'number' => $dossier->number,
        'company_id' => $dossier->company_id,
        'das_id' => $dossier->das_id,
        'responsible_user_id' => null,
        'created_by' => $dossier->created_by,
        'status' => 'running',
        'contractor' => ['type' => 'organisation', 'id' => $x['hotel']->id],
        'price_cents' => 515000,
        'abandon' => ['mode' => 'prorata_dedit', 'dedit_percent' => 30],
    ])->and($data['lines'])->toBe([
        ['id' => $x['perTrainee']->id, 'kind' => 'training', 'pricing_mode' => 'per_trainee', 'label' => 'HACCP', 'total_cents' => 200000, 'unit' => 'stagiaire',
            'quantity' => '2.00', 'unit_price_cents' => 100000, 'discount_percent' => '0.00', 'das_id' => $x['perTrainee']->das_id, 'training_id' => $x['perTrainee']->training_id,
            'session_ids' => [$x['s1']->id]],
        ['id' => $x['group']->id, 'kind' => 'training', 'pricing_mode' => 'group', 'label' => 'SST', 'total_cents' => 300000, 'unit' => 'forfait',
            'quantity' => '1.00', 'unit_price_cents' => 300000, 'discount_percent' => '0.00', 'das_id' => $x['group']->das_id, 'training_id' => $x['group']->training_id,
            'session_ids' => [$x['s2']->id]],
        ['id' => $x['fee']->id, 'kind' => 'fee', 'pricing_mode' => null, 'label' => 'Déplacement', 'total_cents' => 15000, 'unit' => 'forfait',
            'quantity' => '1.00', 'unit_price_cents' => 15000, 'discount_percent' => '0.00', 'das_id' => $x['fee']->das_id, 'training_id' => null,
            'session_ids' => []],
    ])->and($data['enrolments'])->toBe([
        ['id' => $x['lea']->id, 'line_id' => $x['perTrainee']->id, 'person_id' => $x['lea']->person_id, 'status' => 'enrolled', 'price_share_cents' => 100000,
            'session_id' => $x['s1']->id, 'entered_on' => '2026-09-01', 'left_on' => null, 'abandoned' => false, 'force_majeure' => false],
        ['id' => $x['tom']->id, 'line_id' => $x['perTrainee']->id, 'person_id' => $x['tom']->person_id, 'status' => 'abandoned', 'price_share_cents' => 100000,
            'session_id' => $x['s1']->id, 'entered_on' => '2026-09-01', 'left_on' => '2026-10-01', 'abandoned' => true, 'force_majeure' => false],
        ['id' => $x['marc']->id, 'line_id' => $x['group']->id, 'person_id' => $x['marc']->person_id, 'status' => 'enrolled', 'price_share_cents' => null,
            'session_id' => $x['s2']->id, 'entered_on' => '2026-10-01', 'left_on' => null, 'abandoned' => false, 'force_majeure' => false],
    ])->and($data['fundings'])->toBe([
        ['id' => $x['opco']->id, 'funder' => ['type' => 'organisation', 'id' => $x['opco']->funder_organisation_id], 'type_id' => crmItem(ReferenceList::FundingType, 'OPCO')->id,
            'external_billing' => false, 'amount_cents' => 120000, 'status' => 'agreed', 'agreement_number' => 'PEC-2026-42', 'pays_directly' => true, 'line_id' => null, 'enrolment_id' => null],
        ['id' => $x['cpfFunding']->id, 'funder' => ['type' => 'organisation', 'id' => $x['cpfFunding']->funder_organisation_id], 'type_id' => $x['cpf']->id,
            'external_billing' => true, 'amount_cents' => 50000, 'status' => 'requested', 'agreement_number' => null, 'pays_directly' => false, 'line_id' => null, 'enrolment_id' => $x['lea']->id],
    ])->and($data['sessions'])->toBe([
        ['id' => $x['s1']->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-11-30', 'status' => 'running', 'label' => "HACCP 3 jours ({$x['s1']->number})"],
        ['id' => $x['s2']->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-30', 'status' => 'running', 'label' => "Sauveteur secouriste du travail ({$x['s2']->number})"],
    ]);

    expect(Billing::dossier('0195f5b0-0000-7000-8000-000000000000'))->toBeNull();
});

it('flags a force majeure abandon, which never costs a dédit', function () {
    $x = bsSeed();
    $x['tom']->update(['abandon_reason_id' => crmItem(ReferenceList::AbandonReason, 'Maladie')->id]);
    $maladie = crmItem(ReferenceList::AbandonReason, 'Maladie');
    $maladie->meta = ['force_majeure' => true];
    $maladie->save();

    $tom = collect(Billing::dossier($x['dossier']->id)['enrolments'])->firstWhere('id', $x['tom']->id);

    expect($tom['force_majeure'])->toBeTrue();
});

it('gives the realised, planned and unjustified absent minutes per trainee and per group session', function () {
    $x = bsSeed();

    $hours = Billing::hours($x['dossier']->id, null);

    expect($hours['enrolments'])->toBe([
        // 180 present + 120 partial + 400 interval; absent 180 (3/09) + 60 (5/10); the cancelled séance never counts.
        ['enrolment_id' => $x['lea']->id, 'session_id' => $x['s1']->id, 'planned_minutes' => 1260, 'realised_minutes' => 700, 'absent_minutes' => 240,
            'all_months_closed' => false, 'closed_months' => ['2026-09']],
        // Excused 2/09 counts no absence; 5/10 is after his leaving day.
        ['enrolment_id' => $x['tom']->id, 'session_id' => $x['s1']->id, 'planned_minutes' => 1260, 'realised_minutes' => 180, 'absent_minutes' => 0,
            'all_months_closed' => false, 'closed_months' => ['2026-09']],
        ['enrolment_id' => $x['marc']->id, 'session_id' => $x['s2']->id, 'planned_minutes' => 1260, 'realised_minutes' => 240, 'absent_minutes' => 0,
            'all_months_closed' => false, 'closed_months' => ['2026-10']],
    ])->and($hours['sessions'])->toBe([
        // Held séances 12/10 + 13/10 (360) + interval hours (240); 14/10 cancelled, 30/11 to come.
        ['session_id' => $x['s2']->id, 'planned_minutes' => 1860, 'realised_minutes' => 600, 'all_months_closed' => false, 'closed_months' => ['2026-10']],
    ]);

    // Up to October: closed months only (September on S1, October on S2).
    $closed = Billing::hours($x['dossier']->id, '2026-10');
    expect(collect($closed['enrolments'])->map(fn ($e) => [$e['realised_minutes'], $e['absent_minutes']])->all())->toBe([[480, 180], [180, 0], [240, 0]])
        ->and($closed['sessions'][0]['realised_minutes'])->toBe(600);

    // Up to September: S2 has nothing closed yet.
    $sept = Billing::hours($x['dossier']->id, '2026-09');
    expect($sept['sessions'][0]['realised_minutes'])->toBe(0)
        ->and($sept['enrolments'][2]['realised_minutes'])->toBe(0);

    // Every month of S2 closed.
    app(Closures::class)->close($x['s2']->refresh(), '2026-11');
    $all = Billing::hours($x['dossier']->id, null);
    expect($all['sessions'][0]['all_months_closed'])->toBeTrue()
        ->and($all['sessions'][0]['closed_months'])->toBe(['2026-10', '2026-11'])
        ->and($all['enrolments'][2]['all_months_closed'])->toBeTrue();
});

it('reads the hours of a dossier in a fixed number of queries', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $interval = atIntervalModule($session);
    $seances = [plSeance($module, '2026-09-02'), plSeance($module, '2026-09-03'), plSeance($module, '2026-10-05')];
    $dossier = l2Dossier();
    $enrol = function (int $n) use ($dossier, $session, $seances, $interval): void {
        for ($i = 0; $i < $n; $i++) {
            $e = l2Enrol($dossier, $session, makePerson('Stagiaire'.$i.uniqid()));
            foreach ($seances as $k => $s) {
                app(Attendances::class)->record($s, $e->id, ['present', 'absent', 'partial'][$k], 60, null);
            }
            app(MonthlyHours::class)->record($e->id, $interval, '2026-09', 30, null);
        }
    };

    $enrol(2);
    DB::enableQueryLog();
    Billing::hours($dossier->id, null);
    Billing::dossier($dossier->id);
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    $enrol(6);
    DB::enableQueryLog();
    Billing::hours($dossier->id, null);
    Billing::dossier($dossier->id);
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few);
});

it('lists the dossiers of the user\'s scope for the invoicing lists', function () {
    $x = bsSeed();
    $other = l2Dossier(null, null, ['company_id' => Company::factory()->create()->id]);
    $all = l2User([Permissions::DOSSIERS_VIEW => null]);
    $own = l2User([Permissions::DOSSIERS_VIEW => AccessScope::Own]);
    $none = l2User([Permissions::SESSIONS_VIEW => null]);

    expect(Billing::dossiersFor($all, [])->pluck('id')->sort()->values()->all())->toBe(collect([$x['dossier']->id, $other->id])->sort()->values()->all())
        ->and(Billing::dossiersFor($all, ['company_id' => $other->company_id])->pluck('id')->all())->toBe([$other->id])
        ->and(Billing::dossiersFor($own, [])->pluck('id')->all())->toBe([])
        ->and(Billing::dossiersFor($none, [])->pluck('id')->all())->toBe([]);
});

it('records whether a funder pays the organisme directly, never the contractor itself', function () {
    $x = bsSeed();
    $writer = app(FundingWriter::class);

    expect($x['opco']->fresh()->pays_directly)->toBeTrue()
        ->and(l2Funding($x['dossier'])->pays_directly)->toBeFalse()
        ->and(FundingWriter::input($x['opco']->fresh())['pays_directly'])->toBeTrue();

    // An update without the key keeps it.
    $writer->update($x['opco'], ['amount' => '1 300']);
    expect($x['opco']->fresh()->pays_directly)->toBeTrue();
    $writer->update($x['opco'], ['pays_directly' => false]);
    expect($x['opco']->fresh()->pays_directly)->toBeFalse();

    // The contractor ticked « paie directement »: accepted, the box ignored (he is invoiced the rest anyway).
    $own = l2Funding($x['dossier'], ['funder_organisation_id' => $x['hotel']->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Entreprise')->id, 'pays_directly' => true]);
    expect($own->pays_directly)->toBeFalse();
    $own->delete();
    // The contractor as a funder not paying directly stays possible.
    expect(l2Funding($x['dossier'], ['funder_organisation_id' => $x['hotel']->id, 'pays_directly' => false])->pays_directly)->toBeFalse();

    // Through the page: the checkbox is sent, the row shows it.
    $writer->update($x['opco'], ['pays_directly' => true]);
    $manager = l2User([Permissions::DOSSIERS_VIEW => null, Permissions::DOSSIERS_UPDATE => null]);
    $this->actingAs($manager)->post("/dossiers/{$x['dossier']->id}/fundings", [
        'funder_organisation_id' => $x['hotel']->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Entreprise')->id,
        'target' => 'dossier', 'amount' => '100', 'status' => 'agreed', 'pays_directly' => true,
    ])->assertSessionHasNoErrors();
    $this->get("/dossiers/{$x['dossier']->id}")->assertInertia(fn (Assert $page) => $page
        ->where('fundings.0.pays_directly', true)->where('fundings.1.pays_directly', false));
});

it('lets an administrator mark a funding type as billed outside the TMS', function () {
    $admin = admin(twoFactor: true);
    $cpf = crmItem(ReferenceList::FundingType, 'CPF');

    $this->actingAs($admin)->get('/admin/references')->assertInertia(fn (Assert $page) => $page
        ->where('lists.funding_type.has_external_billing', true)->where('lists.abandon_reason.has_external_billing', false));

    $this->put("/admin/references/funding_type/{$cpf->id}", ['label' => 'CPF', 'meta' => ['external_billing' => true]])->assertRedirect();
    expect($cpf->fresh()->meta)->toBe(['external_billing' => true]);

    $this->put("/admin/references/funding_type/{$cpf->id}", ['label' => 'CPF', 'meta' => ['external_billing' => false]])->assertRedirect();
    expect($cpf->fresh()->meta)->toBeNull();

    // Only on funding types.
    $reason = crmItem(ReferenceList::AbandonReason, 'Autre');
    $this->put("/admin/references/abandon_reason/{$reason->id}", ['label' => 'Autre', 'meta' => ['external_billing' => true]])->assertRedirect();
    expect($reason->fresh()->meta)->toBeNull();
});

it('marks a done dossier invoiced and back, journaled, refused before done', function () {
    $dossier = l2Dossier();
    $states = app(DossierStates::class);

    expect(fn () => $states->markInvoiced($dossier->id))->toThrow(UserFacingHttpException::class, DossierTransitions::NOT_DONE);

    app(DossierTransitions::class)->markDone($dossier);
    $states->markInvoiced($dossier->id);
    expect($dossier->fresh()->status)->toBe(DossierStatus::Invoiced);
    // Already invoiced: nothing changes.
    $states->markInvoiced($dossier->id);
    expect($dossier->fresh()->status)->toBe(DossierStatus::Invoiced);

    $states->unmarkInvoiced($dossier->id);
    expect($dossier->fresh()->status)->toBe(DossierStatus::Done);
    // Not invoiced: nothing changes.
    $states->unmarkInvoiced($dossier->id);
    expect($dossier->fresh()->status)->toBe(DossierStatus::Done);

    $changes = DB::table('audit_logs')->where('auditable_id', $dossier->id)->where('event', 'updated')->orderBy('id')->pluck('new_values')
        ->map(fn ($v) => json_decode((string) $v, true)['status'] ?? null)->filter()->values()->all();
    expect($changes)->toBe(['done', 'invoiced', 'done']);
});

it('marks invoiced a dossier done by the dates of its sessions', function () {
    $dossier = l2Dossier();
    l2Enrol($dossier, l2Session(null, ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-07']), makePerson('Durand'));

    app(DossierStates::class)->markInvoiced($dossier->id);

    expect($dossier->fresh()->status)->toBe(DossierStatus::Invoiced);
});

it('shows on the dossier page the blocks other modules register', function () {
    $dossier = l2Dossier();
    $viewer = l2User([Permissions::DOSSIERS_VIEW => null]);
    DossierBlocks::register('finance.invoices', fn (string $id, User $user) => $id === $dossier->id
        ? ['title' => 'Factures', 'empty' => 'Aucune facture.', 'rows' => [['id' => 'f1', 'title' => 'F-2026-00001']]]
        : null);
    DossierBlocks::register('finance.hidden', fn () => null);

    expect(DossierBlocks::for($dossier->id, $viewer))->toBe([
        ['key' => 'finance.invoices', 'title' => 'Factures', 'empty' => 'Aucune facture.', 'rows' => [['id' => 'f1', 'title' => 'F-2026-00001']]],
    ]);
    $this->actingAs($viewer)->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->has('blocks', 1)->where('blocks.0.title', 'Factures'));
});
