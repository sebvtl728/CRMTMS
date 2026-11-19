<?php

use App\Modules\Finance\Payments\Payment;
use App\Modules\Finance\Permissions;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

it('reserves Finance › Règlements to the payments right', function () {
    payInvoice();

    $this->actingAs(fiManager())->get('/finance/payments')->assertForbidden();
    $this->actingAs(fiManager([Permissions::PAYMENTS => null]))->get('/finance/payments')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('finance/payments/index')->has('payments.data', 0));
});

it('prefills the form from an invoice', function () {
    [$invoice, , $user] = payInvoice();

    $this->actingAs($user)->get('/finance/payments/create?invoice='.$invoice->id)
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('finance/payments/form')
        ->where('payment.payer.id', $invoice->recipient_id)
        ->where('payment.payer.label', 'Hôtel du Parc')
        ->where('payment.company_id', $invoice->company_id)
        ->where('payment.amount', '171,43')
        ->where('payment.allocations.0.invoice_id', $invoice->id)
        ->where('payment.allocations.0.amount', '171,43')
        ->has('open_invoices', 1)
        ->has('options.methods', 5));
});

it('records a payment from an invoice and goes back to it', function () {
    [$invoice, , $user] = payInvoice();

    $this->actingAs($user)->post('/finance/payments', [
        'from_invoice' => $invoice->id,
        'payer_type' => 'organisation', 'payer_id' => $invoice->recipient_id, 'company_id' => $invoice->company_id,
        'received_on' => '2026-11-17', 'amount' => '171,43', 'method_id' => payMethod(), 'reference' => 'VIR 1',
        'allocations' => [['invoice_id' => $invoice->id, 'amount' => '171,43']],
    ])->assertRedirect('/finance/invoices/'.$invoice->id)->assertSessionHas('status', 'Règlement enregistré.');
    // The invoice page shows the message (the flash is passed to it).
    $this->actingAs($user)->withSession(['status' => 'Règlement enregistré.'])->get('/finance/invoices/'.$invoice->id)
        ->assertInertia(fn (Assert $p) => $p->where('status', 'Règlement enregistré.')->where('balance.status_label', 'Soldée'));

    $this->actingAs($user)->post('/finance/payments', [
        'payer_type' => 'organisation', 'payer_id' => $invoice->recipient_id, 'company_id' => $invoice->company_id,
        'received_on' => '2026-11-17', 'amount' => '10', 'method_id' => payMethod(), 'allocations' => [],
    ])->assertRedirect('/finance/payments');
    expect(Payment::query()->count())->toBe(2);
});

it('shows the payments of an invoice to who reads it, without the notes', function () {
    [$invoice, , $user] = payInvoice();
    payRecord($user, $invoice, 10000, ['notes' => 'Appel du comptable']);
    $reader = l2User([App\Modules\Sessions\Permissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]);

    $this->actingAs($reader)->get('/finance/invoices/'.$invoice->id)
        ->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('balance.paid_cents', 10000)
        ->where('balance.remaining_cents', 7143)
        ->where('balance.status_label', 'Partiellement réglée')
        ->where('payments.0.amount_cents', 10000)
        ->where('payments.0.method', 'Virement')
        ->missing('payments.0.notes')
        ->where('can.record_payment', false));
    $this->actingAs($user)->get('/finance/invoices/'.$invoice->id)
        ->assertInertia(fn (Assert $p) => $p->where('can.record_payment', true));
});

it('edits and deletes a payment', function () {
    [$invoice, , $user] = payInvoice();
    $payment = payRecord($user, $invoice, 10000);

    $this->actingAs($user)->get('/finance/payments/'.$payment->id.'/edit')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('finance/payments/form')
        ->where('payment.id', $payment->id)->where('payment.amount', '100,00')
        ->where('open_invoices.0.remaining_cents', 17143));

    $this->actingAs($user)->delete('/finance/payments/'.$payment->id)
        ->assertRedirect('/finance/payments')->assertSessionHas('status', 'Règlement supprimé.');
    expect(Payment::query()->count())->toBe(0);
});

it('searches the payers and their open invoices', function () {
    [$invoice, , $user] = payInvoice();

    $this->actingAs($user)->getJson('/finance/payments/payers?q=hotel')
        ->assertOk()->assertJsonPath('payers.0.id', $invoice->recipient_id)->assertJsonPath('payers.0.type', 'organisation');
    $this->actingAs($user)->getJson('/finance/payments/open-invoices?payer_type=organisation&payer_id='.$invoice->recipient_id.'&company_id='.$invoice->company_id)
        ->assertOk()->assertJsonPath('invoices.0.id', $invoice->id);
});
