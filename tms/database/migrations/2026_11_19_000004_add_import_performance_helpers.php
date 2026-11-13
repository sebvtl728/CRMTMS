<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Comparison keys of the duplicate detection, as stored columns.
     *
     * Under row-level security PostgreSQL only uses an index for a condition
     * made of leakproof functions on the columns: lower(email),
     * regexp_replace(phone…), unaccent(city) are not, so the expression
     * indexes of 2026_11_17_000001 were never used and every duplicate check
     * scanned the whole organisme (an import became quadratic). A plain
     * comparison with a stored column (text =) is leakproof: these columns
     * and their indexes are what DuplicateFinder now queries.
     */
    private const KEYS = [
        'organisations' => [
            'dup_email' => 'lower(email)',
            'dup_phone' => "nullif(regexp_replace(phone, '\\D', '', 'g'), '')",
            'dup_postal_code' => "nullif(trim(postal_code), '')",
            'dup_city' => "nullif(public.immutable_unaccent(lower(trim(city))), '')",
        ],
        'people' => [
            'dup_email' => 'lower(email)',
            'dup_email_secondary' => 'lower(email_secondary)',
            'dup_postal_code' => "nullif(trim(postal_code), '')",
            'dup_city' => "nullif(public.immutable_unaccent(lower(trim(city))), '')",
        ],
    ];

    /** Expression indexes that row-level security made useless. */
    private const UNUSABLE = ['organisations_email_lower', 'organisations_phone_digits', 'people_email_lower', 'people_email_secondary_lower'];

    public function up(): void
    {
        foreach (self::KEYS as $table => $columns) {
            foreach ($columns as $column => $expression) {
                DB::statement("alter table {$table} add column {$column} text generated always as ({$expression}) stored");
                DB::statement("create index {$table}_{$column} on {$table} (tenant_id, {$column}) where {$column} is not null and deleted_at is null");
            }
        }
        foreach (self::UNUSABLE as $index) {
            DB::statement("drop index if exists {$index}");
        }

        // A bulk import grows these tables faster than autovacuum refreshes
        // their statistics; with stale ones the planner ignores the indexes
        // above. The runtime role is not the owner and may not ANALYZE: this
        // owner function, with a fixed table list, lets the import do it.
        DB::statement(<<<'SQL'
            create or replace function public.crm_refresh_statistics() returns void
            language plpgsql security definer set search_path = public, pg_temp as $$
            begin
                analyze public.organisations;
                analyze public.people;
                analyze public.affiliations;
            end
            $$
            SQL);
        DB::statement('revoke all on function public.crm_refresh_statistics() from public');
        $runtime = (string) config('database.connections.pgsql.username');
        if ($runtime !== '' && $runtime !== DB::connection()->getConfig('username')) {
            DB::statement('grant execute on function public.crm_refresh_statistics() to '.DB::connection()->getQueryGrammar()->wrap($runtime));
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.crm_refresh_statistics()');
        DB::statement('create index if not exists organisations_email_lower on organisations (tenant_id, lower(email)) where email is not null and deleted_at is null');
        DB::statement("create index if not exists organisations_phone_digits on organisations (tenant_id, regexp_replace(phone, '\\D', '', 'g')) where phone is not null and deleted_at is null");
        DB::statement('create index if not exists people_email_lower on people (tenant_id, lower(email)) where email is not null and deleted_at is null');
        DB::statement('create index if not exists people_email_secondary_lower on people (tenant_id, lower(email_secondary)) where email_secondary is not null and deleted_at is null');
        foreach (self::KEYS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                DB::statement("alter table {$table} drop column if exists {$column}");
            }
        }
    }
};
