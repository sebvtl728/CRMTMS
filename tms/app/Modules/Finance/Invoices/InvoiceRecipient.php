<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Public\Billing;

/**
 * Who an invoice is addressed to and what it refers to, as printed (spec
 * 0.9 § 4.4): the identity of the recipient (Crm\Public\Fiches: name,
 * address, SIRET / SIREN, VAT number, billing e-mail), the number of the
 * dossier and, for a funder, the agreement number of its funding. Copied
 * into recipient_snapshot at validation (InvoiceValidator, part of the
 * hashed content); a validated invoice is then always printed from that
 * copy, a draft from the fiches as they are today.
 */
final class InvoiceRecipient
{
    public const KEYS = ['name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'siret', 'siren', 'vat_number', 'email', 'dossier_number', 'agreement_number'];

    /**
     * @return array<string, string|null>
     */
    public static function of(Invoice $invoice): array
    {
        $values = is_array($invoice->recipient_snapshot) ? $invoice->recipient_snapshot : self::current($invoice);
        $printed = [];
        foreach (self::KEYS as $key) {
            $printed[$key] = isset($values[$key]) && is_scalar($values[$key]) && (string) $values[$key] !== '' ? (string) $values[$key] : null;
        }
        $printed['name'] ??= '-';

        return $printed;
    }

    /**
     * The recipient as the fiches and the dossier describe it today (and,
     * on a free invoice, the names of its co-contractors keyed by id).
     *
     * @return array<string, string|array<string, string>|null>
     */
    public static function current(Invoice $invoice): array
    {
        $identity = app(Fiches::class)->billingIdentity($invoice->recipient_type, $invoice->recipient_id) ?? ['name' => '-'];
        $agreement = null;
        if ($invoice->funding_id !== null && $invoice->dossier_id !== null) {
            foreach (Billing::dossier($invoice->dossier_id)['fundings'] ?? [] as $funding) {
                if ($funding['id'] === $invoice->funding_id) {
                    $agreement = $funding['agreement_number'];
                }
            }
        }

        // A free invoice names the co-contractors of its lines (frozen with the recipient).
        $cotraitants = self::liveCotraitants($invoice);

        return [
            ...$identity,
            ...($cotraitants === [] ? [] : ['cotraitants' => $cotraitants]),
            'dossier_number' => $invoice->dossier_id === null ? null : (Billing::numbers([$invoice->dossier_id])[$invoice->dossier_id]['number'] ?? null),
            'agreement_number' => $agreement === null || trim((string) $agreement) === '' ? null : trim((string) $agreement),
        ];
    }

    /**
     * Names of the co-contractors of the lines, keyed by id: frozen at
     * validation, as the fiches name them today on a draft.
     *
     * @return array<string, string>
     */
    public static function cotraitants(Invoice $invoice): array
    {
        if (is_array($invoice->recipient_snapshot)) {
            $frozen = $invoice->recipient_snapshot['cotraitants'] ?? [];

            return is_array($frozen) ? array_map('strval', $frozen) : [];
        }

        return self::liveCotraitants($invoice);
    }

    /** @return array<string, string> */
    private static function liveCotraitants(Invoice $invoice): array
    {
        $ids = InvoiceLine::query()->where('invoice_id', $invoice->id)->whereNotNull('cotraitant_id')->distinct()->pluck('cotraitant_id')->all();

        return array_map(fn (array $o) => $o['label'], app(Fiches::class)->organisationLabels($ids));
    }
}
