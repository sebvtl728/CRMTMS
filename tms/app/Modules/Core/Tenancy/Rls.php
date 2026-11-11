<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL Row-Level Security for business tables. Call from the migration
 * that creates the table (migrations run as the owner, pgsql_owner):
 *
 *     Rls::enable('companies');
 *
 * FORCE makes the policy apply to the table owner too. The policy compares
 * tenant_id with the session setting written by TenantContext; an empty or
 * missing setting yields NULL, so no row is visible or writable.
 */
final class Rls
{
    public const POLICY = 'tenant_isolation';

    public const EXPRESSION = "tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid";

    public static function enable(string $table, ?string $connection = null): void
    {
        $db = DB::connection($connection);
        $wrapped = $db->getQueryGrammar()->wrapTable($table);

        $db->statement("alter table {$wrapped} enable row level security");
        $db->statement("alter table {$wrapped} force row level security");
        $db->statement(sprintf(
            'create policy %s on %s using (%s) with check (%s)',
            self::POLICY, $wrapped, self::EXPRESSION, self::EXPRESSION,
        ));
    }
}
