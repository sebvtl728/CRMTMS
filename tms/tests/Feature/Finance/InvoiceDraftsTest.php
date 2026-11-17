<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Finance\Invoices\Due;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceDrafts;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Planning\Seance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * A dossier of 1 000 € HT (HACCP, per trainee) for Léa Durand on a session
 * of September 2026 (21 h planned): four séances of 3 h, Léa present at the
 * first three (9 h realised), September closed; the OPCO agreed, paying
 * directly, 600 € on the whole dossier; the company exempt from VAT.
 *
 * B = 1 000 € × 540 / 1 260 = 428,57 € ; OPCO = 600 € × 428,57 / 1 000 = 257,14 € ; contractor 171,43 €.
 *
 * @return array<string, mixed>
 */
function fiSeed(bool $opco = true, bool $close = true, string $vat = 'exempt'): array
{
    $dossier = l2Dossier();
    Company::query()->whereKey($dossier->company_id)->update(['vat_regime' => $vat]);
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $seances = [];
    foreach (['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07'] as $i => $day) {
        $seances[$i] = plSeance($module, $day, '09:00', '12:00');
        if ($i < 3) {
            app(Attendances::class)->record($seances[$i], $lea->id, 'present', null, null);
        }
    }
    if ($close) {
        app(Closures::class)->close($session->refresh(), '2026-09');
    }
    $funding = $opco ? l2Funding($dossier, ['amount' => '600,00', 'status' => 'agreed', 'agreement_number' => 'PEC-42', 'agreed_on' => '2026-09-01', 'pays_directly' => true]) : null;

    return compact('dossier', 'session', 'module', 'lea', 'seances', 'funding');
}

/** Changes an attendance of a closed September (reopened, then closed again). */
function fiLateAttendance(array $x, int $seance, string $status): void
{
    app(Closures::class)->reopen($x['session']->refresh(), '2026-09', 'Feuille retrouvée');
    /** @var Seance $s */
    $s = $x['seances'][$seance];
    app(Attendances::class)->record($s, $x['lea']->id, $status, null, null);
    app(Closures::class)->close($x['session']->refresh(), '2026-09');
}

/**
 * Who reads and prepares the invoicing of every dossier.
 *
 * @param  array<string, AccessScope|null>  $extra
 */
function fiManager(array $extra = []): User
{
    return l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null, Permissions::MANAGE => null, ...$extra]);
}

/**
 * Validation is Task 5: a draft is validated here by hand (number, date).
 */
function fiValidate(Invoice $invoice): Invoice
{
    static $n = 0;
    $n++;
    $invoice->forceFill([
        'status' => InvoiceStatus::Validated, 'number' => ($invoice->kind === Invoice::CREDIT_NOTE ? 'AV' : 'F').'-2026-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'issued_on' => '2026-11-17', 'validated_at' => CarbonImmutable::now(),
    ])->save();

    return $invoice;
}

/**
 * The due rows of the dossier keyed by recipient (« contractor », « funding:<id> »).
 *
 * @return array<string, array<string, mixed>>
 */
function fiDue(string $dossierId): array
{
    return collect(app(Due::class)->forDossier($dossierId)['rows'])->keyBy('recipient_key')->all();
}

function fiDrafts(): InvoiceDrafts
{
    return app(InvoiceDrafts::class);
}

// ---------------------------------------------------------------- due rows

it('computes what is due to the contractor and to the direct funder, with labelled lines', function () {
    $x = fiSeed();
    $rows = fiDue($x['dossier']->id);
    $opco = "funding:{$x['funding']->id}";

    expect(array_keys($rows))->toBe(['contractor', $opco])
        ->and($rows['contractor']['kind'])->toBe('invoice')
        ->and($rows['contractor']['total_cents'])->toBe(17143)
        ->and($rows['contractor']['components']['training'])->toBe(['share' => 17143, 'invoiced' => 0, 'due' => 17143])
        ->and($rows['contractor']['recipient']['label'])->toBe('Hôtel du Parc')
        ->and($rows['contractor']['rhythm'])->toBe('end')
        ->and($rows['contractor']['draft'])->toBeNull()
        ->and($rows['contractor']['lines'])->toHaveCount(1)
        ->and($rows['contractor']['lines'][0])->toMatchArray([
            'component' => 'training', 'label' => 'HACCP 3 jours — du 01/09/2026 au 30/09/2026 — 9 h réalisées / 21 h prévues',
            'total_ht_cents' => 17143, 'realised_minutes' => 540, 'planned_minutes' => 1260,
        ])
        ->and($rows[$opco]['total_cents'])->toBe(25714)
        ->and($rows[$opco]['recipient'])->toMatchArray(['role' => 'funder', 'type' => 'organisation', 'label' => 'OPCO EP', 'funding_id' => $x['funding']->id])
        // A funder's invoice names the trainees.
        ->and($rows[$opco]['lines'][0]['description'])->toBe('Stagiaires : Léa Durand')
        // The detail of the computation, for checking.
        ->and($rows['contractor']['detail'][0])->toMatchArray(['planned' => 1260, 'realised' => 540, 'ratio' => '0.4286', 'amount_cents' => 42857]);
});

it('shows a dossier not ready yet only as « bientôt à facturer », with its reason', function () {
    $x = fiSeed(close: false);

    $due = app(Due::class)->forDossier($x['dossier']->id);

    expect($due['rows'])->toBe([])
        ->and($due['soon'])->toHaveCount(1)
        ->and($due['soon'][0])->toMatchArray(['reason' => 'Formation non terminée', 'rhythm' => 'end']);
});

it('bills each closed month of a monthly plan, oldest first, one draft per month', function () {
    $dossier = l2Dossier();
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    app(Attendances::class)->record(plSeance($module, '2026-09-02', '09:00', '12:00'), $lea->id, 'present', null, null);
    app(Attendances::class)->record(plSeance($module, '2026-10-02', '09:00', '12:00'), $lea->id, 'present', null, null);
    app(Closures::class)->close($session->refresh(), '2026-09');
    app(Closures::class)->close($session->refresh(), '2026-10');
    app(BillingPlans::class)->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised']);

    $rows = fiDue($dossier->id);
    // 1 000 € × 180 / 1 260 for September.
    expect($rows['contractor'])->toMatchArray(['cut_off' => '2026-09', 'total_cents' => 14286])
        ->and($rows['contractor']['period'])->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and($rows['contractor']['lines'][0]['label'])->toBe('HACCP 3 jours — septembre 2026 — 3 h réalisées / 21 h prévues');

    $september = fiDrafts()->prepare(fiManager(), [$rows['contractor']['key']]);
    expect($september['created'])->toBe(1)
        ->and(fiDue($dossier->id)['contractor']['draft'])->not->toBeNull();
    fiValidate(Invoice::query()->sole());

    // October: cumulated 360 min − September already invoiced.
    $rows = fiDue($dossier->id);
    expect($rows['contractor'])->toMatchArray(['cut_off' => '2026-10', 'total_cents' => 28571 - 14286])
        ->and($rows['contractor']['components']['training'])->toBe(['share' => 28571, 'invoiced' => 14286, 'due' => 14285]);
});

it('bills a schedule: the contractor alone at each instalment, the funder at the last one', function () {
    $x = fiSeed();
    app(BillingPlans::class)->save($x['dossier']->id, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => [
        ['due_on' => '2026-09-01', 'percent' => 30], ['due_on' => '2026-10-15', 'percent' => 70],
    ]]);
    $opco = "funding:{$x['funding']->id}";

    // First instalment: (1 000 € − the OPCO's planned 600 €) × 30 %; the OPCO waits for the last one.
    $rows = fiDue($x['dossier']->id);
    expect($rows['contractor'])->toMatchArray(['cut_off' => '2026-09-01', 'total_cents' => 12000])
        ->and($rows[$opco])->toMatchArray(['cut_off' => '2026-11-17', 'total_cents' => 25714]);

    fiDrafts()->prepare(fiManager(), [$rows['contractor']['key']]);
    fiValidate(Invoice::query()->sole());

    // Last instalment, at the realised hours: 171,43 € − 120 € already invoiced.
    expect(fiDue($x['dossier']->id)['contractor'])->toMatchArray(['cut_off' => '2026-11-17', 'total_cents' => 5143]);
});

it('proposes the funder\'s invoice and a credit note to the contractor when a funding is agreed later (Review Focus 2)', function () {
    $x = fiSeed(opco: false);
    $requested = l2Funding($x['dossier'], ['amount' => '600,00', 'status' => 'requested', 'pays_directly' => true]);

    // Requested only: the contractor is invoiced the whole 428,57 €.
    $rows = fiDue($x['dossier']->id);
    expect(array_keys($rows))->toBe(['contractor'])->and($rows['contractor']['total_cents'])->toBe(42857);
    fiDrafts()->prepare(fiManager(), [$rows['contractor']['key']]);
    $invoice = fiValidate(Invoice::query()->sole());

    app(FundingWriter::class)->update($requested->refresh(), [
        ...FundingWriter::input($requested), 'status' => 'agreed', 'agreement_number' => 'PEC-7', 'agreed_on' => '2026-10-01',
    ]);
    $rows = fiDue($x['dossier']->id);

    expect($rows['contractor'])->toMatchArray(['kind' => 'credit_note', 'total_cents' => 17143 - 42857, 'corrected_invoice_id' => $invoice->id])
        ->and($rows["funding:{$requested->id}"])->toMatchArray(['kind' => 'invoice', 'total_cents' => 25714]);
});

// ---------------------------------------------------------------- preparing

it('prepares one draft per recipient, and never twice (Review Focus 1)', function () {
    $x = fiSeed();
    $keys = array_column(fiDue($x['dossier']->id), 'key');

    expect(fiDrafts()->prepare(fiManager(), $keys))->toBe(['created' => 2, 'skipped' => 0]);
    $contractor = Invoice::query()->whereNull('funding_id')->sole();
    expect($contractor)->kind->toBe('invoice')->status->toBe(InvoiceStatus::Draft)->number->toBeNull()
        ->recipient_type->toBe('organisation')->recipient_id->toBe($x['dossier']->account_organisation_id)
        ->dossier_id->toBe($x['dossier']->id)->cut_off->toBe('2026-11-17')
        ->total_ht_cents->toBe(17143)->total_vat_cents->toBe(0)->total_ttc_cents->toBe(17143)
        ->vat_mention->toBe('Exonération de TVA, article 261-4-4° du CGI')
        ->and($contractor->lines()->sole())->kind->toBe('computed')->component->toBe('training')->total_ht_cents->toBe(17143)->computed_cents->toBe(17143);
    $funder = Invoice::query()->whereNotNull('funding_id')->sole();
    expect($funder)->funding_id->toBe($x['funding']->id)->total_ht_cents->toBe(25714);

    // A second « Préparer » (double click, another user) finds a draft in progress.
    expect(fiDrafts()->prepare(fiManager(), $keys))->toBe(['created' => 0, 'skipped' => 2])
        ->and(Invoice::query()->count())->toBe(2);
    $rows = fiDue($x['dossier']->id);
    expect($rows['contractor']['draft'])->toBe(['id' => $contractor->id, 'url' => "/finance/invoices/{$contractor->id}"]);

    // Once validated: nothing left to invoice, the dossier is no longer listed.
    fiValidate($contractor);
    fiValidate($funder);
    expect(app(Due::class)->forDossier($x['dossier']->id))->toBe(['rows' => [], 'soon' => []]);
    expect(fiDrafts()->prepare(fiManager(), $keys))->toBe(['created' => 0, 'skipped' => 2]);
});

it('proposes the complement when hours are entered after a validated invoice (Review Focus 5)', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    Invoice::query()->get()->each(fn (Invoice $i) => fiValidate($i));

    // Léa's fourth séance is entered late: 720 min, B = 571,43 €, OPCO 342,86 €, contractor 228,57 €.
    fiLateAttendance($x, 3, 'present');
    $rows = fiDue($x['dossier']->id);

    expect($rows['contractor'])->toMatchArray(['kind' => 'invoice', 'total_cents' => 22857 - 17143])
        ->and($rows['contractor']['components']['training'])->toBe(['share' => 22857, 'invoiced' => 17143, 'due' => 5714])
        ->and($rows["funding:{$x['funding']->id}"]['total_cents'])->toBe(34286 - 25714);
});

it('proposes a credit note when the hours finally went down', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    Invoice::query()->get()->each(fn (Invoice $i) => fiValidate($i));
    $invoiced = Invoice::query()->whereNull('funding_id')->sole();

    // The third séance was in fact an absence: 360 min, B = 285,71 €, OPCO 171,43 €, contractor 114,28 €.
    fiLateAttendance($x, 2, 'absent');
    $rows = fiDue($x['dossier']->id);
    expect($rows['contractor'])->toMatchArray(['kind' => 'credit_note', 'total_cents' => 11428 - 17143, 'corrected_invoice_id' => $invoiced->id]);

    fiDrafts()->prepare(fiManager(), [$rows['contractor']['key']]);
    $credit = Invoice::query()->where('kind', 'credit_note')->sole();
    expect($credit)->corrected_invoice_id->toBe($invoiced->id)->total_ht_cents->toBe(-5715)
        ->and($credit->lines()->sole())->total_ht_cents->toBe(-5715)->unit_price_cents->toBe(-5715);

    // Once validated, the credit note settles it.
    fiValidate($credit);
    expect(fiDue($x['dossier']->id))->not->toHaveKey('contractor');
});

it('never counts free lines, nor what a computed line was changed to, beyond the invoiced amount', function () {
    $x = fiSeed(opco: false);
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    $draft = Invoice::query()->sole();
    $computed = $draft->lines()->sole();

    fiDrafts()->update($draft, ['notes' => null, 'lines' => [
        ['id' => $computed->id, 'label' => $computed->label, 'description' => null, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '428,57', 'vat_rate' => '0'],
        ['label' => 'Repas', 'description' => null, 'quantity' => '2', 'unit' => 'forfait', 'unit_price' => '15,50', 'vat_rate' => '0'],
    ]]);
    $draft->refresh();
    expect($draft->total_ht_cents)->toBe(42857 + 3100)
        ->and($draft->lines()->orderBy('position')->get()->map(fn (InvoiceLine $l) => [$l->kind, $l->total_ht_cents])->all())
        ->toBe([['computed', 42857], ['free', 3100]]);

    fiValidate($draft);
    // Computed lines count at their invoiced amount, free lines never.
    expect(fiDue($x['dossier']->id))->toBe([]);
});

it('mentions a share billed outside the TMS on the contractor\'s invoice, never invoicing it', function () {
    $cpf = crmItem(ReferenceList::FundingType, 'CPF');
    $cpf->forceFill(['meta' => [...($cpf->meta ?? []), 'external_billing' => true]])->save();
    $x = fiSeed(opco: false);
    $caisse = Organisation::query()->create(['name' => 'Caisse des dépôts']);
    l2Funding($x['dossier'], ['funder_organisation_id' => $caisse->id, 'funding_type_id' => $cpf->id, 'amount' => '200,00', 'status' => 'agreed', 'agreed_on' => '2026-09-01']);

    $rows = fiDue($x['dossier']->id);
    // CPF: 200 € × 428,57 / 1 000 = 85,71 € ; contractor 342,86 €.
    expect(array_keys($rows))->toBe(['contractor'])
        ->and($rows['contractor']['total_cents'])->toBe(34286)
        ->and(array_column($rows['contractor']['lines'], 'label'))->toContain('Part réglée par Caisse des dépôts');
    $mention = collect($rows['contractor']['lines'])->firstWhere('component', 'mention');
    expect($mention['total_ht_cents'])->toBe(0);
});

it('bills the dédit and the absences to the contractor on their own lines', function () {
    $x = fiSeed(opco: false, close: false);
    app(Attendances::class)->record($x['seances'][3], $x['lea']->id, 'absent', null, null);
    app(Closures::class)->close($x['session']->refresh(), '2026-09');
    app(BillingPlans::class)->save($x['dossier']->id, ['rhythm' => 'end', 'basis' => 'realised', 'unjustified_absences' => 'bill_contractor']);

    $row = fiDue($x['dossier']->id)['contractor'];
    // Absent 180 min: 1 000 € × 180 / 1 260.
    expect($row['components']['absences'])->toBe(['share' => 14286, 'invoiced' => 0, 'due' => 14286])
        ->and(collect($row['lines'])->firstWhere('component', 'absences'))->toMatchArray([
            'label' => 'Absences non justifiées — non imputables sur les fonds de la formation professionnelle', 'total_ht_cents' => 14286,
        ])
        ->and($row['total_cents'])->toBe(42857 + 14286);
});

// ---------------------------------------------------------------- drafts

it('creates a draft by hand from the dossier, edits it and deletes it', function () {
    $x = fiSeed();
    $manager = fiManager();

    $this->actingAs($manager)->post("/finance/dossiers/{$x['dossier']->id}/invoices", ['recipient' => 'contractor'])
        ->assertRedirect();
    $draft = Invoice::query()->sole();
    expect($draft->total_ht_cents)->toBe(17143);

    // A second time: the draft in progress is opened, none created.
    $this->actingAs($manager)->post("/finance/dossiers/{$x['dossier']->id}/invoices", ['recipient' => 'contractor'])
        ->assertRedirect("/finance/invoices/{$draft->id}");
    expect(Invoice::query()->count())->toBe(1);

    $this->actingAs($manager)->get("/finance/invoices/{$draft->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('finance/invoices/show')
        ->where('invoice.status', 'draft')
        ->where('invoice.recipient.label', 'Hôtel du Parc')
        ->where('invoice.dossier.number', $x['dossier']->number)
        ->has('invoice.lines', 1)
        ->where('can.update', true));

    $computed = $draft->lines()->sole();
    $this->actingAs($manager)->put("/finance/invoices/{$draft->id}", ['notes' => 'Bon de commande 12', 'lines' => [
        ['id' => $computed->id, 'label' => 'HACCP — septembre', 'description' => null, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '171,43', 'vat_rate' => '0'],
        ['label' => 'Supports', 'description' => null, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '-12,00', 'vat_rate' => '0'],
    ]])->assertRedirect()->assertSessionHasNoErrors();
    expect($draft->refresh()->total_ht_cents)->toBe(17143 - 1200)
        ->and($draft->notes)->toBe('Bon de commande 12');

    // A computed line cannot be removed, a label is required, an amount is French.
    $this->actingAs($manager)->put("/finance/invoices/{$draft->id}", ['lines' => [
        ['label' => '', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => 'abc', 'vat_rate' => '0'],
        ['label' => 'Trop', 'quantity' => '100000,01', 'unit' => 'forfait', 'unit_price' => '10 000 000,00', 'vat_rate' => '0'],
    ]])->assertSessionHasErrors([
        'lines' => 'Une ligne calculée ne se supprime pas : mettez son montant à 0.',
        'lines.0.label' => 'Indiquez le libellé de la ligne.',
        'lines.0.unit_price' => 'Indiquez un montant valide (ex. 1 200,50).',
        'lines.1.quantity' => 'La quantité ne peut pas dépasser 100 000.',
        'lines.1.unit_price' => 'Le prix unitaire ne peut pas dépasser 9 999 999,99 €.',
    ]);

    $this->actingAs($manager)->delete("/finance/invoices/{$draft->id}")->assertRedirect('/finance/invoices');
    expect(Invoice::query()->count())->toBe(0)->and(InvoiceLine::query()->count())->toBe(0);
});

it('never changes nor deletes a validated invoice through the drafts', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    $invoice = fiValidate(Invoice::query()->whereNull('funding_id')->sole());

    $this->actingAs(fiManager())->put("/finance/invoices/{$invoice->id}", ['lines' => []])->assertStatus(409);
    $this->actingAs(fiManager())->delete("/finance/invoices/{$invoice->id}")->assertStatus(409);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Validated);
});

it('uses the line VAT rate of a company subject to VAT, never on the dédit', function () {
    $x = fiSeed(opco: false, vat: 'subject');
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));

    $draft = Invoice::query()->sole();
    expect($draft->vat_mention)->toBeNull()
        ->and($draft->lines()->sole()->vat_rate)->toBe('20.00')
        // 428,57 € × 20 % = 85,71 €.
        ->and([$draft->total_ht_cents, $draft->total_vat_cents, $draft->total_ttc_cents])->toBe([42857, 8571, 51428]);
});

it('never invoices a funding billed outside the TMS after it was invoiced: a credit note at most', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    Invoice::query()->get()->each(fn (Invoice $i) => fiValidate($i));
    $opcoType = crmItem(ReferenceList::FundingType, 'OPCO');
    $opcoType->forceFill(['meta' => [...($opcoType->meta ?? []), 'external_billing' => true]])->save();

    fiLateAttendance($x, 3, 'present');
    $rows = fiDue($x['dossier']->id);
    $opco = "funding:{$x['funding']->id}";

    // Its share leaves the contractor's invoice (342,86 € of 571,43 €) and its invoice is cancelled by a credit note.
    expect($rows[$opco])->toMatchArray(['kind' => 'credit_note', 'total_cents' => -25714])
        ->and($rows['contractor'])->toMatchArray(['kind' => 'invoice', 'total_cents' => 22857 - 17143]);

    fiDrafts()->prepare(fiManager(), [$rows[$opco]['key']]);
    expect(Invoice::query()->where('funding_id', $x['funding']->id)->where('status', 'draft')->sole()->kind)->toBe('credit_note');
});

it('recalculates a draft from what is due now, keeping its free lines', function () {
    $x = fiSeed(opco: false);
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    $draft = Invoice::query()->sole();
    $computed = $draft->lines()->sole();
    fiDrafts()->update($draft, ['lines' => [
        ['id' => $computed->id, 'label' => 'Changé', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '1,00', 'vat_rate' => '0'],
        ['label' => 'Repas', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '15,00', 'vat_rate' => '0'],
    ]]);
    // Léa's fourth séance entered meanwhile: 571,43 €.
    fiLateAttendance($x, 3, 'present');

    $this->actingAs(fiManager())->post("/finance/invoices/{$draft->id}/recompute")
        ->assertRedirect()->assertSessionHas('status', 'Brouillon recalculé.');
    $lines = $draft->refresh()->lines()->get();
    expect($lines->map(fn (InvoiceLine $l) => [$l->position, $l->kind, $l->total_ht_cents])->all())
        ->toBe([[0, 'computed', 57143], [1, 'free', 1500]])
        ->and($draft->total_ht_cents)->toBe(57143 + 1500)
        ->and(Invoice::query()->count())->toBe(1);

    fiValidate($draft);
    $this->actingAs(fiManager())->post("/finance/invoices/{$draft->id}/recompute")->assertStatus(409);
    $this->actingAs(l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]))
        ->post("/finance/invoices/{$draft->id}/recompute")->assertForbidden();
});

it('proposes a credit note on a dossier cancelled after its invoices, still listed', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    Invoice::query()->get()->each(fn (Invoice $i) => fiValidate($i));
    $x['dossier']->forceFill(['status' => 'cancelled', 'cancelled_on' => '2026-11-17', 'cancelled_reason_id' => crmItem(ReferenceList::CancelReason, 'Autre')->id])->save();
    fiLateAttendance($x, 2, 'absent');

    $this->actingAs(fiManager())->get('/finance/to-invoice')->assertInertia(fn (Assert $page) => $page
        ->has('rows', 2)
        ->where('rows.0.recipient_key', 'contractor')
        ->where('rows.0.kind', 'credit_note')
        ->where('rows.0.total_cents', 11428 - 17143));
});

it('keeps one open draft per recipient in the database too', function () {
    $x = fiSeed(opco: false);
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    $draft = Invoice::query()->sole();

    expect(fn () => Invoice::query()->create([...$draft->only(['company_id', 'kind', 'dossier_id', 'recipient_type', 'recipient_id', 'rhythm', 'basis'])]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lists the invoices of a dossier in the trash', function () {
    $x = fiSeed(opco: false);
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    fiValidate(Invoice::query()->sole());
    $x['dossier']->delete();

    $this->actingAs(fiManager())->get('/finance/invoices')->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
});

// ---------------------------------------------------------------- pages, rights and scope

it('lists what is due on the « À facturer » page, with filters', function () {
    $x = fiSeed();
    fiSeed(close: false);

    $this->actingAs(fiManager())->get('/finance/to-invoice')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('finance/to-invoice')
        ->has('rows', 2)
        ->where('rows.0.dossier.id', $x['dossier']->id)
        ->where('rows.0.total_cents', 17143)
        ->has('soon', 1)
        ->where('soon.0.reason', 'Formation non terminée')
        ->where('can.manage', true)
        ->has('options.company')->has('options.das')->has('options.responsible'));

    // Month: the end of the period invoiced.
    $this->actingAs(fiManager())->get('/finance/to-invoice?month=2026-10')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs(fiManager())->get('/finance/to-invoice?month=2026-09')->assertInertia(fn (Assert $page) => $page->has('rows', 2));

    $keys = array_column(fiDue($x['dossier']->id), 'key');
    $this->actingAs(fiManager())->post('/finance/to-invoice/prepare', ['keys' => $keys])
        ->assertRedirect()->assertSessionHas('status', '2 brouillons préparés.');
    $this->actingAs(fiManager())->get('/finance/to-invoice')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.draft.id', Invoice::query()->whereNull('funding_id')->value('id')));

    $this->actingAs(fiManager())->get('/finance/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('finance/invoices/index')->has('invoices.data', 2));
});

it('keeps the pages to who holds the rights, within the scope of the dossier', function () {
    $x = fiSeed();
    $keys = array_column(fiDue($x['dossier']->id), 'key');

    $this->get('/finance/to-invoice')->assertRedirect('/login');
    $this->actingAs(l2User([SessionsPermissions::DOSSIERS_VIEW => null]))->get('/finance/to-invoice')->assertForbidden();
    $reader = l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]);
    $this->actingAs($reader)->get('/finance/to-invoice')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->actingAs($reader)->post('/finance/to-invoice/prepare', ['keys' => $keys])->assertForbidden();

    // « Ses fiches » only: not his dossier.
    $own = fiManager([Permissions::VIEW => AccessScope::Own, Permissions::MANAGE => AccessScope::Own]);
    $this->actingAs($own)->get('/finance/to-invoice')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs($own)->post('/finance/to-invoice/prepare', ['keys' => $keys])->assertSessionHas('status', 'Aucun brouillon préparé.');
    expect(Invoice::query()->count())->toBe(0);

    fiDrafts()->prepare(fiManager(), $keys);
    $draft = Invoice::query()->whereNull('funding_id')->sole();
    $this->actingAs($own)->get("/finance/invoices/{$draft->id}")->assertNotFound();
    $this->actingAs($own)->get('/finance/invoices')->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
    $this->actingAs($reader)->get("/finance/invoices/{$draft->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false));
    $this->actingAs($reader)->put("/finance/invoices/{$draft->id}", ['lines' => []])->assertForbidden();
    $this->actingAs($reader)->delete("/finance/invoices/{$draft->id}")->assertForbidden();
    $this->actingAs(fiManager())->get('/finance/invoices/not-a-uuid')->assertNotFound();

    // His own dossier (responsible for it).
    $x['dossier']->forceFill(['responsible_user_id' => $own->id])->save();
    $this->actingAs($own)->get('/finance/to-invoice')->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    $this->actingAs($own)->get("/finance/invoices/{$draft->id}")->assertOk();
});

it('lists the invoices of the dossier in its billing block', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    $contractor = fiValidate(Invoice::query()->whereNull('funding_id')->sole());

    $this->actingAs(fiManager())->get("/dossiers/{$x['dossier']->id}")->assertInertia(fn (Assert $page) => $page
        ->where('blocks.0.key', 'finance.billing')
        ->has('blocks.0.rows', 2)
        ->where('blocks.0.rows.0.title', 'Facture '.$contractor->number)
        ->where('blocks.0.rows.0.badge', 'Validée')
        ->where('blocks.0.rows.0.href', "/finance/invoices/{$contractor->id}")
        ->where('blocks.0.rows.1.badge', 'Brouillon')
        ->where('blocks.0.props.create_url', "/finance/dossiers/{$x['dossier']->id}/invoices")
        ->has('blocks.0.props.recipients', 2)
        ->has('blocks.0.props.direct_fundings', 1)
        ->where('blocks.0.props.due_url', "/finance/dossiers/{$x['dossier']->id}/due"));

    // What is left to invoice is read apart, in the scope of finance.invoices.view.
    $this->actingAs(fiManager())->getJson("/finance/dossiers/{$x['dossier']->id}/due")->assertOk()
        ->assertJson(['due' => [['recipient' => 'OPCO EP', 'kind' => 'invoice', 'total_cents' => 25714]], 'soon' => null]);
    $this->actingAs(fiManager([Permissions::VIEW => AccessScope::Own]))->getJson("/finance/dossiers/{$x['dossier']->id}/due")->assertNotFound();
});

// ---------------------------------------------------------------- RLS

it('keeps invoices inside their organisme', function () {
    $x = fiSeed();
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    expect(Invoice::query()->count())->toBe(2);

    $foreign = Tenant::factory()->create();
    app(TenantContext::class)->run($foreign, function () use ($x) {
        expect(Invoice::query()->count())->toBe(0)
            ->and(InvoiceLine::query()->count())->toBe(0)
            ->and(DB::table('invoice_lines')->count())->toBe(0)
            ->and(app(Due::class)->forDossier($x['dossier']->id))->toBe(['rows' => [], 'soon' => []]);
    });
});
