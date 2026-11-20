<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Permissions;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Sessions/Support/lot2a.php';
require_once __DIR__.'/../../Sessions/Support/planning.php';

/** Gotenberg answers « %PDF-1.7 libre N »; the HTML stays readable by frSentText(). */
function frGotenberg(): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $calls = 0;
    Http::fake(['gotenberg:3000/*' => function () use (&$calls) {
        $calls++;

        return Http::response("%PDF-1.7 libre {$calls}", 200, ['Content-Type' => 'application/pdf']);
    }]);
}

/** The text of the last HTML sent to Gotenberg (tags removed, spaces squeezed). */
function frSentText(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });
    $html = (string) preg_replace(['#<style\b.*?</style>#s', '#<[^>]+>#'], ' ', $html);

    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{202F}"], ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5))));
}

/** The buyer of a public market (the Région). */
function frRegion(): Organisation
{
    return Organisation::query()->create([
        'name' => 'Région Pays de la Loire', 'siret' => '23440003400026', 'address_line1' => '1 rue de la Loire',
        'postal_code' => '44966', 'city' => 'Nantes', 'email' => 'factures@region.test',
    ]);
}

/** A co-contractor of the grouping. */
function frCotraitant(string $name = 'Formation Atlantique'): Organisation
{
    return Organisation::query()->create(['name' => $name]);
}

/** Who sees, prepares and validates every invoice (scope « Tout »); $scope narrows finance.invoices.*. */
function frAccountant(?AccessScope $scope = null): User
{
    return l2User([
        SessionsPermissions::DOSSIERS_VIEW => null,
        Permissions::VIEW => $scope, Permissions::MANAGE => $scope, Permissions::VALIDATE => $scope, Permissions::PAYMENTS => null,
    ]);
}

function frCompany(): Company
{
    $company = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();
    $company->forceFill(['name' => 'CAP FORMATION', 'siret' => '73282932000074', 'nda_number' => '84740123474', 'vat_regime' => 'exempt'])->save();

    return $company;
}

/**
 * A free draft for the Région written straight in the tables (Task 1), with
 * the given lines [label, cents, cotraitant id|null].
 *
 * @param  list<array{0: string, 1: int, 2: string|null}>  $lines
 */
function frDraft(Organisation $buyer, array $lines, array $over = []): Invoice
{
    $invoice = Invoice::query()->create([
        'company_id' => frCompany()->id, 'kind' => Invoice::INVOICE, 'origin' => Invoice::ORIGIN_FREE,
        'recipient_type' => 'organisation', 'recipient_id' => $buyer->id, 'rhythm' => 'free', 'basis' => 'free', ...$over,
    ]);
    $total = 0;
    foreach ($lines as $i => [$label, $cents, $cotraitant]) {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => $i, 'kind' => InvoiceLine::FREE, 'label' => $label, 'quantity' => '1',
            'unit' => 'forfait', 'unit_price_cents' => $cents, 'vat_rate' => '0.00', 'total_ht_cents' => $cents, 'cotraitant_id' => $cotraitant,
        ]);
        $total += $cents;
    }
    $invoice->forceFill(['total_ht_cents' => $total, 'total_ttc_cents' => $total])->save();

    return $invoice->refresh();
}
