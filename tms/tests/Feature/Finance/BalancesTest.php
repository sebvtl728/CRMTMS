<?php

use App\Modules\Finance\Invoices\CreditNotes;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Payments\PaymentAllocation;
use App\Modules\Finance\Payments\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

it('leaves a validated invoice unpaid, overdue the day after its due date', function () {
    [$invoice] = payInvoice();

    $b = Balances::of([$invoice->id])[$invoice->id];
    expect($b['ttc_cents'])->toBe(17143)
        ->and($b['remaining_cents'])->toBe(17143)
        ->and($b['paid_cents'])->toBe(0)
        ->and($b['status'])->toBe(PaymentStatus::Unpaid)
        ->and($b['overdue'])->toBeFalse()
        ->and($invoice->due_on->toDateString())->toBe('2026-12-17');

    $this->travelTo(CarbonImmutable::parse('2026-12-17 23:00', 'Europe/Paris'));
    expect(Balances::one($invoice)['overdue'])->toBeFalse();
    $this->travelTo(CarbonImmutable::parse('2026-12-18 00:30', 'Europe/Paris'));
    expect(Balances::one($invoice)['overdue'])->toBeTrue();
});

it('is partially paid, then paid', function () {
    [$invoice, , $user] = payInvoice();

    payRecord($user, $invoice, 10000);
    expect(Balances::one($invoice))->toMatchArray(['paid_cents' => 10000, 'remaining_cents' => 7143, 'status' => PaymentStatus::Partial]);

    payRecord($user, $invoice, 7143);
    expect(Balances::one($invoice))->toMatchArray(['paid_cents' => 17143, 'remaining_cents' => 0, 'status' => PaymentStatus::Paid, 'overdue' => false]);
});

it('counts a validated credit note correcting the invoice', function () {
    [$invoice, , $user] = payInvoice();
    $credit = app(CreditNotes::class)->create($user, $invoice, null);

    // A draft credit note does not count yet.
    expect(Balances::one($invoice)['credited_cents'])->toBe(0);

    app(InvoiceValidator::class)->validate($credit->refresh(), $user);
    expect(Balances::one($invoice))->toMatchArray(['credited_cents' => 17143, 'remaining_cents' => 0, 'status' => PaymentStatus::Paid, 'overpaid' => false]);
});

it('shows an overpayment when a credit note follows a full payment', function () {
    [$invoice, , $user] = payInvoice();
    payRecord($user, $invoice, 17143);
    $credit = app(CreditNotes::class)->create($user, $invoice, null);
    app(InvoiceValidator::class)->validate($credit->refresh(), $user);

    expect(Balances::one($invoice))->toMatchArray(['remaining_cents' => -17143, 'status' => PaymentStatus::Paid, 'overpaid' => true, 'overdue' => false]);
});

it('gives no balance to a draft or a credit note', function () {
    [$invoice, , $user] = payInvoice();
    $credit = app(CreditNotes::class)->create($user, $invoice, null);

    expect(Balances::of([$credit->id]))->toBe([])
        ->and(Balances::one($credit))->toBeNull();
    app(InvoiceValidator::class)->validate($credit->refresh(), $user);
    expect(Balances::one($credit->refresh()))->toBeNull();
});

it('reads the balances of many invoices in a fixed number of queries', function () {
    [$invoice, , $user] = payInvoice();
    $second = paySecondInvoice($invoice, $user, 5000);
    PaymentAllocation::query()->count();

    DB::enableQueryLog();
    $balances = Balances::of([$invoice->id, $second->id]);
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3)
        ->and($balances[$second->id]['ttc_cents'])->toBe(5000);
});
