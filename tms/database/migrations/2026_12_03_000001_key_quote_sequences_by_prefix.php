<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quote numbers now follow one sequence per (organisme, prefix, Paris year)
 * instead of one per company: two companies sharing a prefix share it, and a
 * prefix change can never give a number already used.
 *
 * Data: each existing row moves to the current prefix of its company, then
 * every sequence is raised to the highest number already given (quote
 * numbers, trashed quotes included), so no number is ever handed out twice.
 * Run per tenant (FORCE RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table quote_sequences drop constraint quote_sequences_pkey');
        Schema::table('quote_sequences', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable();
            $table->uuid('company_id')->nullable()->change();
        });

        $this->perTenant(function (string $tenantId): void {
            DB::statement('update quote_sequences s set prefix = c.quote_prefix from companies c where c.id = s.company_id');
            DB::statement('delete from quote_sequences where prefix is null');
            // Two companies of one prefix: keep the highest number.
            DB::statement('delete from quote_sequences a using quote_sequences b
                where a.tenant_id = b.tenant_id and a.prefix = b.prefix and a.year = b.year
                  and (a.last_number < b.last_number or (a.last_number = b.last_number and a.ctid > b.ctid))');

            $used = DB::select("select substring(number from '^(.+)-[0-9]{4}-[0-9]+$') as prefix,
                    substring(number from '-([0-9]{4})-[0-9]+$')::int as year,
                    max(substring(number from '-([0-9]+)$')::int) as last_number
                from quotes where number ~ '^.+-[0-9]{4}-[0-9]+$' group by 1, 2");
            foreach ($used as $row) {
                $current = DB::table('quote_sequences')->where('prefix', $row->prefix)->where('year', $row->year)->value('last_number');
                if ($current === null) {
                    DB::table('quote_sequences')->insert(['tenant_id' => $tenantId, 'prefix' => $row->prefix, 'year' => $row->year, 'last_number' => $row->last_number, 'created_at' => now(), 'updated_at' => now()]);
                } elseif ((int) $current < (int) $row->last_number) {
                    DB::table('quote_sequences')->where('prefix', $row->prefix)->where('year', $row->year)->update(['last_number' => $row->last_number, 'updated_at' => now()]);
                }
            }
        });

        Schema::table('quote_sequences', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->string('prefix', 10)->nullable(false)->change();
            $table->primary(['tenant_id', 'prefix', 'year']);
        });
    }

    public function down(): void
    {
        DB::statement('alter table quote_sequences drop constraint quote_sequences_pkey');
        Schema::table('quote_sequences', fn (Blueprint $table) => $table->foreignUuid('company_id')->nullable()->constrained('companies'));

        // Each prefix back to the first company holding it (the default first).
        $this->perTenant(function (): void {
            DB::statement('update quote_sequences s set company_id = (select c.id from companies c where c.quote_prefix = s.prefix
                order by c.deleted_at is not null, c.is_default desc, c.created_at, c.id limit 1)');
            DB::statement('delete from quote_sequences where company_id is null');
            DB::statement('delete from quote_sequences a using quote_sequences b
                where a.company_id = b.company_id and a.year = b.year
                  and (a.last_number < b.last_number or (a.last_number = b.last_number and a.ctid > b.ctid))');
        });

        Schema::table('quote_sequences', function (Blueprint $table) {
            $table->dropColumn('prefix');
            $table->uuid('company_id')->nullable(false)->change();
            $table->primary(['company_id', 'year']);
        });
    }

    /**
     * @param  callable(string): void  $callback
     */
    private function perTenant(callable $callback): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $callback((string) $tenantId);
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }
};
