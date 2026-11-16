<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Session numbers: S-<AAAA>-<NNNN>, one sequence per organisme and Paris
 * calendar year (same mechanism as QuoteNumbering). The atomic upsert on
 * session_sequences runs in the caller's transaction: its row lock makes a
 * concurrent creation wait, and a rolled back creation gives its number back.
 */
class SessionNumbering
{
    public const TIMEZONE = 'Europe/Paris';

    public const PREFIX = 'S';

    /**
     * @throws LogicException outside a transaction (the lock would be released at once)
     */
    public function next(CarbonImmutable $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SessionNumbering::next() doit s\'exécuter dans la transaction qui crée la session.');
        }

        $year = (int) $at->setTimezone(self::TIMEZONE)->format('Y');

        $row = DB::selectOne(
            'insert into session_sequences (tenant_id, year, last_number, created_at, updated_at)
             values (?, ?, 1, now(), now())
             on conflict (tenant_id, year) do update
                set last_number = session_sequences.last_number + 1, updated_at = now()
             returning last_number',
            [app(TenantContext::class)->id(), $year],
        );

        return sprintf('%s-%d-%04d', self::PREFIX, $year, (int) $row->last_number);
    }
}
