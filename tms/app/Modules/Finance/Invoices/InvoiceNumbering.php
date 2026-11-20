<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Companies\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;

/**
 * Invoice and credit-note numbers (spec 0.9 § 4.4): <préfixe>-<AAAA>-<NNNNN>,
 * continuous per company, kind (invoice / credit note) and Paris calendar
 * year; the prefix is the company's invoice_prefix or credit_note_prefix.
 * The counter belongs to the company and the kind, not to the prefix: a
 * renamed prefix goes on counting, so a number is never given twice.
 *
 * The mechanics of QuoteNumbering: an atomic upsert on invoice_sequences,
 * run in the validation transaction; the row lock it takes makes a
 * concurrent validation of the same company and kind wait until this one
 * commits or rolls back - no duplicate, and a rolled back validation gives
 * its number back (no gap).
 */
class InvoiceNumbering
{
    public const TIMEZONE = 'Europe/Paris';

    /**
     * @param  string  $kind  Invoice::INVOICE or Invoice::CREDIT_NOTE
     *
     * @throws LogicException outside a transaction (the lock would be released at once)
     */
    public function next(Company $company, string $kind, CarbonImmutable $at): string
    {
        $db = $company->getConnection();
        if ($db->transactionLevel() === 0) {
            throw new LogicException('InvoiceNumbering::next() doit s\'exécuter dans la transaction qui valide la facture.');
        }
        if (! in_array($kind, [Invoice::INVOICE, Invoice::CREDIT_NOTE], true)) {
            throw new LogicException("Type de pièce inconnu : {$kind}.");
        }

        $year = (int) $at->setTimezone(self::TIMEZONE)->format('Y');

        $row = $db->selectOne(
            'insert into invoice_sequences (id, tenant_id, company_id, kind, year, last_number, created_at, updated_at)
             values (?, ?, ?, ?, ?, 1, now(), now())
             on conflict (tenant_id, company_id, kind, year) do update
                set last_number = invoice_sequences.last_number + 1, updated_at = now()
             returning last_number',
            [(string) Str::uuid7(), $company->tenant_id, $company->id, $kind, $year],
        );
        $prefix = $kind === Invoice::CREDIT_NOTE ? $company->credit_note_prefix : $company->invoice_prefix;

        return sprintf('%s-%d-%05d', $prefix, $year, (int) $row->last_number);
    }
}
