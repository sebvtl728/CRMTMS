<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\Due;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceDrafts;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Permissions;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Planning\Seance;
use Carbon\CarbonImmutable;

/*
 * Fixtures of the invoicing tests (0.9): a dossier billed at the realised
 * hours, its users and shortcuts. Needs Sessions/Support/lot2a.php and
 * planning.php, and plSetUp() in beforeEach.
 */

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
