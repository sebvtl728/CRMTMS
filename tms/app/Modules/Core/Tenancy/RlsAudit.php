<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Lists the tables of the public schema that break the isolation rule
 * (tenant_id uuid not null + RLS enabled and forced + the tenant_isolation
 * policy, and no other permissive policy that would widen access).
 * Tables listed in config('tms.tenancy.unscoped_tables') are skipped.
 */
final class RlsAudit
{
    /** Policy expression as PostgreSQL stores it for Rls::EXPRESSION. */
    private const CANONICAL_QUAL = "(tenant_id = (NULLIF(current_setting('app.tenant_id'::text, true), ''::text))::uuid)";

    /**
     * @return array<string, list<string>> table => problems (French)
     */
    public function violations(string $connection = 'pgsql_owner'): array
    {
        $db = DB::connection($connection);
        /** @var list<string> $whitelist */
        $whitelist = config('tms.tenancy.unscoped_tables', []);

        $tables = $db->select(<<<'SQL'
            select c.relname as name, c.relrowsecurity as enabled, c.relforcerowsecurity as forced
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind in ('r', 'p')
            order by c.relname
            SQL);

        $columns = collect($db->select(<<<'SQL'
            select c.relname as table_name, format_type(a.atttypid, a.atttypmod) as type, a.attnotnull as not_null
            from pg_attribute a join pg_class c on c.oid = a.attrelid join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and a.attname = 'tenant_id' and not a.attisdropped
            SQL))->keyBy('table_name');

        $policies = collect($db->select(<<<'SQL'
            select tablename, policyname, permissive, cmd, roles::text as roles, qual, with_check
            from pg_policies where schemaname = 'public'
            SQL))->groupBy('tablename');

        $violations = [];

        foreach ($tables as $table) {
            if (in_array($table->name, $whitelist, true)) {
                continue;
            }

            $problems = [];
            $column = $columns->get($table->name);

            if ($column === null) {
                $problems[] = 'colonne tenant_id absente';
            } elseif ($column->type !== 'uuid' || ! $column->not_null) {
                $problems[] = 'tenant_id doit être uuid not null';
            }

            if (! $table->enabled) {
                $problems[] = 'RLS non activée';
            }

            if (! $table->forced) {
                $problems[] = 'RLS non forcée';
            }

            $tablePolicies = $policies->get($table->name, collect());
            $isolation = $tablePolicies->firstWhere('policyname', Rls::POLICY);

            if ($isolation === null) {
                $problems[] = 'politique '.Rls::POLICY.' absente';
            } elseif ($isolation->permissive !== 'PERMISSIVE' || $isolation->cmd !== 'ALL' || $isolation->roles !== '{public}'
                || $isolation->qual !== self::CANONICAL_QUAL || $isolation->with_check !== self::CANONICAL_QUAL) {
                $problems[] = 'politique '.Rls::POLICY.' non conforme';
            }

            foreach ($tablePolicies as $policy) {
                if ($policy->policyname !== Rls::POLICY && $policy->permissive === 'PERMISSIVE') {
                    $problems[] = 'politique permissive supplémentaire : '.$policy->policyname;
                }
            }

            if ($problems !== []) {
                $violations[$table->name] = $problems;
            }
        }

        return $violations;
    }
}
