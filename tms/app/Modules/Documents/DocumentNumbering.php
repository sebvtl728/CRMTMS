<?php

namespace App\Modules\Documents;

use App\Modules\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * DOC-<année de Paris>-<NNNNN>, continuous per organisme and year: the
 * mechanics of QuoteNumbering (atomic upsert in the issuing transaction; a
 * rolled back issue gives its number back).
 */
class DocumentNumbering
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @throws LogicException outside a transaction
     */
    public function next(?CarbonImmutable $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumbering::next() doit s\'exécuter dans la transaction qui émet le document.');
        }
        $year = (int) ($at ?? CarbonImmutable::now())->setTimezone('Europe/Paris')->format('Y');
        $row = DB::selectOne(
            'insert into document_sequences (id, tenant_id, year, last_number, created_at, updated_at)
             values (?, ?, ?, 1, now(), now())
             on conflict (tenant_id, year) do update
                set last_number = document_sequences.last_number + 1, updated_at = now()
             returning last_number',
            [(string) Str::uuid7(), $this->tenants->id(), $year],
        );

        return sprintf('DOC-%d-%05d', $year, (int) $row->last_number);
    }
}
