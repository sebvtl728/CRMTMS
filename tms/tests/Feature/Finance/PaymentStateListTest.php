<?php

use App\Modules\Finance\Plans\BillingBlock;
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

it('shows what is paid and left on each invoice of the list', function () {
    [$invoice, , $user] = payInvoice();
    payRecord($user, $invoice, 10000);

    $this->actingAs($user)->get('/finance/invoices')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('invoices.data.0.paid_cents', 10000)
        ->where('invoices.data.0.remaining_cents', 7143)
        ->where('invoices.data.0.payment_status', 'partial')
        ->where('invoices.data.0.payment_status_label', 'Partiellement réglée')
        ->where('invoices.data.0.overdue', false)
        ->has('options.payment', 2));
});

it('filters the open and the overdue invoices', function () {
    [$invoice, , $user] = payInvoice();
    $second = paySecondInvoice($invoice, $user, 5000);
    payRecord($user, $second, 5000);

    // A fresh session each time: a month later the idle session would have expired.
    $ids = fn (string $filter) => collect($this->flushSession()->actingAs($user)->get('/finance/invoices?payment='.$filter)->viewData('page')['props']['invoices']['data'])->pluck('id')->all();
    expect($ids('open'))->toBe([$invoice->id])
        ->and($ids('overdue'))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-12-18 10:00', 'Europe/Paris'));
    expect($ids('overdue'))->toBe([$invoice->id]);
});

it('exports what is paid, left and the payment state', function () {
    [$invoice, , $user] = payInvoice();
    payRecord($user, $invoice, 10000);

    $csv = $this->actingAs($user)->get('/finance/invoices-export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\u{FEFF}")))));

    expect($lines[0])->toContain('Réglé;"Reste dû";"État de paiement"')
        ->and($lines[1])->toContain('100,00;71,43;"Partiellement réglée"');
});

it('totals the payments of the dossier in its billing block', function () {
    [$invoice, $x, $user] = payInvoice();
    payRecord($user, $invoice, 10000);

    $block = BillingBlock::for($x['dossier']->id, $user);
    expect($block['props']['payments'])->toBe(['paid_cents' => 10000, 'remaining_cents' => 7143, 'overdue' => false])
        ->and($block['rows'][0]['meta'])->toContain('Reste dû');
});
