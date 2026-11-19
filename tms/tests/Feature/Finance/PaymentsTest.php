<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Finance\Invoices\CreditNotes;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Payments\Payment;
use App\Modules\Finance\Payments\PaymentAllocation;
use App\Modules\Finance\Payments\Payments;
use App\Modules\Finance\Payments\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/** The validation error of a key raised by $fn, null when none. */
function payError(Closure $fn, string $key): ?string
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors()[$key][0] ?? json_encode($e->errors());
    }

    return null;
}

it('splits one payment over two invoices of the same payer, the rest not allocated', function () {
    [$invoice, , $user] = payInvoice();
    $second = paySecondInvoice($invoice, $user, 5000);

    $payment = payRecord($user, $invoice, 30000, ['allocations' => [
        ['invoice_id' => $invoice->id, 'amount' => '171,43'],
        ['invoice_id' => $second->id, 'amount' => '50'],
    ]]);

    expect($payment->amount_cents)->toBe(30000)
        ->and((int) $payment->allocations()->sum('amount_cents'))->toBe(22143)
        ->and(Balances::one($invoice)['status'])->toBe(PaymentStatus::Paid)
        ->and(Balances::one($second)['status'])->toBe(PaymentStatus::Paid)
        ->and(AuditLog::query()->where('event', 'payment.recorded')->count())->toBe(1);
});

it('refuses a credit note or a draft', function () {
    [$invoice, , $user] = payInvoice();
    $credit = app(CreditNotes::class)->create($user, $invoice, null);

    expect(payError(fn () => payRecord($user, $invoice, 100, ['allocations' => [['invoice_id' => $credit->id, 'amount' => '1']]]), 'allocations.0.invoice_id'))
        ->toBe(Payments::NOT_PAYABLE);
});

it('refuses an invoice of another payer', function () {
    [$invoice, , $user] = payInvoice();
    $other = Organisation::query()->create(['name' => 'Autre client']);

    expect(payError(fn () => payRecord($user, $invoice, 100, ['payer_id' => $other->id, 'allocations' => [['invoice_id' => $invoice->id, 'amount' => '1']]]), 'allocations.0.invoice_id'))
        ->toBe(Payments::OTHER_PAYER);
});

it('refuses an invoice of another company', function () {
    [$invoice, , $user] = payInvoice();
    $company = Company::query()->create(['name' => 'Autre société', 'vat_regime' => 'exempt']);

    expect(payError(fn () => payRecord($user, $invoice, 100, ['company_id' => $company->id]), 'allocations.0.invoice_id'))
        ->toBe(Payments::OTHER_COMPANY);
});

it('never allocates more than the remaining due of an invoice', function () {
    [$invoice, , $user] = payInvoice();
    payRecord($user, $invoice, 17000);

    expect(payError(fn () => payRecord($user, $invoice, 200), 'allocations.0.amount'))
        ->toBe('Le montant affecté dépasse le reste dû de la facture (1,43 €).')
        ->and(Payment::query()->count())->toBe(1);
});

it('never allocates more than the payment', function () {
    [$invoice, , $user] = payInvoice();

    expect(payError(fn () => payRecord($user, $invoice, 100, ['allocations' => [['invoice_id' => $invoice->id, 'amount' => '2']]]), 'allocations'))
        ->toBe(Payments::OVER_AMOUNT);
});

it('refuses a payment received in the future', function () {
    [$invoice, , $user] = payInvoice();

    expect(payError(fn () => payRecord($user, $invoice, 100, ['received_on' => '2026-11-18']), 'received_on'))->toBe(Payments::FUTURE);
});

it('refuses an unknown payer', function () {
    [$invoice, , $user] = payInvoice();

    expect(payError(fn () => payRecord($user, $invoice, 100, ['payer_id' => (string) Str::uuid7(), 'allocations' => []]), 'payer_id'))
        ->toBe('Choisissez le payeur.');
});

it('keeps an unallocated payment', function () {
    [$invoice, , $user] = payInvoice();

    $payment = payRecord($user, $invoice, 5000, ['allocations' => []]);
    expect($payment->allocations()->count())->toBe(0)
        ->and(Balances::one($invoice)['status'])->toBe(PaymentStatus::Unpaid);
});

it('updates a payment: its allocations replaced, never under what is allocated', function () {
    [$invoice, , $user] = payInvoice();
    $second = paySecondInvoice($invoice, $user, 5000);
    $payment = payRecord($user, $invoice, 10000);

    $data = fn (array $over) => [
        'payer_type' => $invoice->recipient_type, 'payer_id' => $invoice->recipient_id, 'company_id' => $invoice->company_id,
        'received_on' => '2026-11-16', 'amount' => '100', 'method_id' => payMethod('Chèque'), 'reference' => 'CHQ 1', 'notes' => null, ...$over,
    ];
    expect(payError(fn () => app(Payments::class)->update($user, $payment, $data(['amount' => '50', 'allocations' => [['invoice_id' => $invoice->id, 'amount' => '100']]])), 'allocations'))
        ->toBe(Payments::OVER_AMOUNT);

    // Moving the whole payment to the second invoice frees the first; its own allocation never blocks it.
    app(Payments::class)->update($user, $payment, $data(['allocations' => [['invoice_id' => $second->id, 'amount' => '50'], ['invoice_id' => $invoice->id, 'amount' => '50']]]));
    expect(Balances::one($invoice)['paid_cents'])->toBe(5000)
        ->and(Balances::one($second)['status'])->toBe(PaymentStatus::Paid)
        ->and($payment->refresh()->method_id)->toBe(payMethod('Chèque'))
        ->and(AuditLog::query()->where('event', 'payment.updated')->count())->toBe(1);
});

it('deletes a payment and its allocations', function () {
    [$invoice, , $user] = payInvoice();
    $payment = payRecord($user, $invoice, 17143);

    app(Payments::class)->delete($user, $payment);

    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and(Balances::one($invoice)['status'])->toBe(PaymentStatus::Unpaid)
        ->and(AuditLog::query()->where('event', 'payment.deleted')->count())->toBe(1);
});

it('lists the open invoices of a payer, oldest due first', function () {
    [$invoice, , $user] = payInvoice();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
    $second = paySecondInvoice($invoice, $user, 5000);
    $third = paySecondInvoice($invoice, $user, 2000);
    payRecord($user, $third, 2000);

    $open = app(Payments::class)->openInvoices($invoice->recipient_type, $invoice->recipient_id, $invoice->company_id);

    expect(array_column($open, 'id'))->toBe([$invoice->id, $second->id])
        ->and($open[0]['remaining_cents'])->toBe(17143)
        ->and($open[0]['number'])->toBe($invoice->number);
});

it('refuses a second payment racing for the same remaining due', function () {
    [$invoice, , $user] = payInvoice();
    payRecord($user, $invoice, 17143);

    // The remaining due is re-read under the lock of the invoice: nothing left.
    expect(payError(fn () => payRecord($user, Invoice::query()->findOrFail($invoice->id), 1), 'allocations.0.amount'))
        ->toBe('Le montant affecté dépasse le reste dû de la facture (0,00 €).');
});

it('clears the notes and the reference of the payments of an anonymised person', function () {
    [$invoice, , $user] = payInvoice();
    $paul = makePerson('Martin', ['first_name' => 'Paul', 'is_individual' => true]);
    $hisPayment = payRecord($user, $invoice, 5000, ['payer_type' => 'person', 'payer_id' => $paul->id, 'allocations' => [], 'notes' => 'Payé en deux fois', 'reference' => 'CHQ 123']);
    $company = payRecord($user, $invoice, 5000, ['notes' => 'Virement groupé']);

    FicheLinks::anonymise([$paul->id]);

    expect($hisPayment->refresh()->only(['notes', 'reference', 'amount_cents']))->toBe(['notes' => null, 'reference' => null, 'amount_cents' => 5000])
        ->and($company->refresh()->notes)->toBe('Virement groupé')
        ->and($company->allocations()->count())->toBe(1);
});
