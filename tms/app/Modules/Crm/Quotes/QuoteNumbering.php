<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Companies\Company;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Quote numbers: <préfixe>-<AAAA>-<NNNN>, one sequence per company and per
 * Paris calendar year, whatever the prefix (changing it never renumbers).
 *
 * The number comes from an atomic upsert on quote_sequences run in the
 * caller's transaction (the one creating the quote): the row lock it takes
 * makes a concurrent creation wait until this one commits or rolls back, so
 * numbers are never duplicated and a rolled back creation gives its number
 * back (no gap).
 */
class QuoteNumbering
{
    public const TIMEZONE = 'Europe/Paris';

    /**
     * @throws LogicException outside a transaction (the lock would be released at once)
     */
    public function next(Company $company, CarbonImmutable $at): string
    {
        $db = $company->getConnection();
        if ($db->transactionLevel() === 0) {
            throw new LogicException('QuoteNumbering::next() doit s\'exécuter dans la transaction qui crée le devis.');
        }

        $year = (int) $at->setTimezone(self::TIMEZONE)->format('Y');

        $row = $db->selectOne(
            'insert into quote_sequences (tenant_id, company_id, year, last_number, created_at, updated_at)
             values (?, ?, ?, 1, now(), now())
             on conflict (company_id, year) do update
                set last_number = quote_sequences.last_number + 1, updated_at = now()
             returning last_number',
            [$company->tenant_id, $company->getKey(), $year],
        );

        return sprintf('%s-%d-%04d', $company->quote_prefix, $year, (int) $row->last_number);
    }
}
