<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Finance\Invoices\CreditNotes;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Payouts\CotraitantPayout;
use App\Modules\Finance\Payouts\CotraitantPayouts;
use App\Modules\Finance\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/free.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    frGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
    $this->partner = frCotraitant('Formation Atlantique');
    $this->user = frAccountant();
    $this->invoice = app(InvoiceValidator::class)->validate(
        frDraft(frRegion(), [['Composante CAP', 150000, null], ['Composante co-traitant', 100000, $this->partner->id]]), $this->user);
});

function poRow(): array
{
    return collect(app(CotraitantPayouts::class)->owed(test()->user, ['state' => 'all']))->sole();
}

it('owes the co-contractor its lines, less credit notes and payouts', function () {
    expect(poRow())->toMatchArray(['owed_cents' => 100000, 'left_cents' => 100000, 'cotraitant' => 'Formation Atlantique', 'buyer_paid' => false]);

    $line = $this->invoice->lines()->where('cotraitant_id', $this->partner->id)->value('id');
    $credit = app(CreditNotes::class)->create($this->user, $this->invoice, [$line]);
    $credit->lines()->update(['unit_price_cents' => -20000, 'total_ht_cents' => -20000]);
    $credit->forceFill(['total_ht_cents' => -20000, 'total_ttc_cents' => -20000])->save();
    app(InvoiceValidator::class)->validate($credit, $this->user);
    expect(poRow()['left_cents'])->toBe(80000);

    $payouts = app(CotraitantPayouts::class);
    $first = $payouts->record($this->user, $this->invoice->id, $this->partner->id, ['amount' => '500,00', 'paid_on' => '2026-11-20', 'reference' => 'VIR-1']);
    expect(poRow())->toMatchArray(['paid_out_cents' => 50000, 'left_cents' => 30000]);

    expect(fn () => $payouts->record($this->user, $this->invoice->id, $this->partner->id, ['amount' => '400,00', 'paid_on' => '2026-11-20']))
        ->toThrow(ValidationException::class, 'Ce reversement dépasse le reste à reverser (300,00');

    $payouts->delete($this->user, $first);
    expect(poRow()['left_cents'])->toBe(80000);
    expect(app(CotraitantPayouts::class)->owed($this->user))->toHaveCount(1);
});

it('follows the buyer payment and refuses a payout where nothing is owed', function () {
    payRecord($this->user, $this->invoice, 250000);
    expect(poRow()['buyer_paid'])->toBeTrue();

    expect(fn () => app(CotraitantPayouts::class)->record($this->user, $this->invoice->id, (string) Str::uuid7(), ['amount' => '10', 'paid_on' => '2026-11-20']))
        ->toThrow(UserFacingHttpException::class);
});

it('serves the page and the CSV to who manages payments on « Tout » only', function () {
    $this->actingAs($this->user)->post('/finance/payouts', [
        'invoice_id' => $this->invoice->id, 'cotraitant_id' => $this->partner->id, 'amount' => '1 000,00', 'paid_on' => '2026-11-20',
    ])->assertSessionHasNoErrors();
    expect((int) CotraitantPayout::query()->sum('amount_cents'))->toBe(100000);

    $this->actingAs($this->user)->get('/finance/payouts?state=all')->assertOk()
        ->assertInertia(fn ($page) => $page->component('finance/payouts')->where('rows.0.left_cents', 0)->where('rows.0.payouts.0.amount_cents', 100000));
    $this->actingAs($this->user)->get('/finance/payouts')->assertOk()->assertInertia(fn ($page) => $page->where('rows', []));

    $csv = $this->actingAs($this->user)->get('/finance/payouts-export?state=all')->assertOk()->streamedContent();
    $csv = str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $csv);
    expect($csv)->toContain('Facture;"Émise le";Client;Co-traitant')->toContain('"Formation Atlantique";1 000,00;1 000,00;0,00;Non');

    $this->actingAs(frAccountant(AccessScope::Das))->get('/finance/payouts')->assertForbidden();
    $this->actingAs(userWithPermissions([Permissions::VIEW]))->get('/finance/payouts')->assertForbidden();
});
