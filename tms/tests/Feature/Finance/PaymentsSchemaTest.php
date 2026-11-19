<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Finance\Payments\Payment;
use App\Modules\Finance\Payments\PaymentAllocation;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Settings\FinanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
});

it('seeds the five payment methods of an organisme', function () {
    expect(ReferenceItem::query()->where('list', ReferenceList::PaymentMethod->value)->orderBy('position')->pluck('label')->all())
        ->toBe(['Virement', 'Chèque', 'Carte bancaire', 'Espèces', 'Prélèvement']);
});

it('refuses a payment of zero cents', function () {
    expect(fn () => Payment::query()->create(payPaymentRow(['amount_cents' => 0])))->toThrow(QueryException::class);
});

it('allocates a payment once per invoice', function () {
    [$invoice] = payInvoice();
    $payment = Payment::query()->create(payPaymentRow(['payer_type' => $invoice->recipient_type, 'payer_id' => $invoice->recipient_id]));
    PaymentAllocation::query()->create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => 100]);

    expect(fn () => PaymentAllocation::query()->create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => 100]))
        ->toThrow(QueryException::class);
});

it('requires a level for a reminder sending, and none for an invoice sending', function () {
    [$invoice] = payInvoice();
    // A savepoint per insert: a refused row must not abort the test's transaction.
    $row = fn (array $over) => DB::transaction(fn () => DB::table('invoice_sendings')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id,
        'email' => 'a@b.test', 'subject' => 's', 'body' => 'b', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(), ...$over,
    ]));

    expect(fn () => $row(['purpose' => 'reminder']))->toThrow(QueryException::class);
    expect(fn () => $row(['purpose' => 'invoice', 'reminder_level' => 1]))->toThrow(QueryException::class);
    $row(['purpose' => 'reminder', 'reminder_level' => 2]);
    expect(DB::table('invoice_sendings')->where('purpose', 'reminder')->value('reminder_level'))->toBe(2);
});

it('defaults the reminder levels to 7, 30 and 60 days, automatic reminders off', function () {
    expect(FinanceSettings::reminderDays())->toBe([7, 30, 60])
        ->and(FinanceSettings::autoReminders())->toBeFalse();
});

it('declares the unscoped payments right, granted to the administrator and the direction', function () {
    expect(Permissions::all())->toContain(Permissions::PAYMENTS)
        ->and(App\Modules\Core\Access\Permissions::isScoped(Permissions::PAYMENTS))->toBeFalse()
        ->and(Permissions::defaultsFor(Role::Direction))->toContain(Permissions::PAYMENTS)
        ->and(Permissions::defaultsFor(Role::Assistant))->not->toContain(Permissions::PAYMENTS);
});
