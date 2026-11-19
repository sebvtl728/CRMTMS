<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceDrafts;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Payments\Payment;
use App\Modules\Finance\Payments\Payments;
use App\Modules\Finance\Permissions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Fixtures of the payments and reminders tests (0.9.1). Needs
 * Sessions/Support/lot2a.php, planning.php, Finance/Support/invoices.php
 * and plSetUp() in beforeEach, Gotenberg faked for the validations.
 */

/** Fakes Gotenberg (a validation renders the PDF). */
function payGotenberg(): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 facture', 200, ['Content-Type' => 'application/pdf'])]);
}

/**
 * A validated invoice of the contractor (171,43 €, exempt), issued
 * 2026-11-17 when the clock is there, due 30 days later; the contractor's
 * e-mail compta@hotel-du-parc.test.
 *
 * @return array{0: Invoice, 1: array<string, mixed>, 2: User}
 */
function payInvoice(): array
{
    payGotenberg();
    $x = fiSeed();
    Company::query()->where('is_default', true)->update(['name' => 'CAP FORMATION']);
    Organisation::query()->where('name', 'Hôtel du Parc')->update(['email' => 'compta@hotel-du-parc.test']);
    $accountant = fiManager([Permissions::VALIDATE => null, Permissions::PAYMENTS => null]);
    fiDrafts()->prepare($accountant, [$x['dossier']->id.'|contractor']);
    $draft = Invoice::query()->where('dossier_id', $x['dossier']->id)->where('status', InvoiceStatus::Draft)->sole();
    $invoice = app(InvoiceValidator::class)->validate($draft, $accountant)->refresh();

    return [$invoice, $x, $accountant];
}

/** A second validated invoice to the same contractor: one free line of $cents (exempt). */
function paySecondInvoice(Invoice $first, User $user, int $cents): Invoice
{
    $draft = app(InvoiceDrafts::class)->manual($user, $first->dossier_id, 'contractor');
    app(InvoiceDrafts::class)->update($draft, ['lines' => [[
        'kind' => 'free', 'label' => 'Frais de dossier', 'quantity' => '1', 'unit' => 'forfait',
        'unit_price' => number_format($cents / 100, 2, ',', ''), 'vat_rate' => '0',
    ]]]);

    return app(InvoiceValidator::class)->validate($draft->refresh(), $user)->refresh();
}

/** The id of a payment method (« Virement » by default). */
function payMethod(string $label = 'Virement'): string
{
    return (string) ReferenceItem::query()->where('list', ReferenceList::PaymentMethod->value)->where('label', $label)->value('id');
}

/** @return array<string, mixed> a raw payments row (no allocation) */
function payPaymentRow(array $over = []): array
{
    return [
        'company_id' => Company::query()->where('is_default', true)->value('id'),
        'payer_type' => 'organisation',
        'payer_id' => Organisation::query()->value('id') ?? (string) Str::uuid7(),
        'received_on' => '2026-11-18',
        'amount_cents' => 1000,
        'method_id' => payMethod(),
        ...$over,
    ];
}

/** Records a payment of $cents on $invoice through Payments::record. */
function payRecord(User $user, Invoice $invoice, int $cents, array $over = []): Payment
{
    return app(Payments::class)->record($user, [
        'payer_type' => $invoice->recipient_type,
        'payer_id' => $invoice->recipient_id,
        'company_id' => $invoice->company_id,
        'received_on' => now('Europe/Paris')->toDateString(),
        'amount' => number_format($cents / 100, 2, ',', ''),
        'method_id' => payMethod(),
        'reference' => 'VIR 42',
        'notes' => null,
        'allocations' => [['invoice_id' => $invoice->id, 'amount' => number_format($cents / 100, 2, ',', '')]],
        ...$over,
    ]);
}
