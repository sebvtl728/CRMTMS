<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A quote prefix belongs to one company of the organisme (among the
 * companies not deleted). Existing collisions: the default company keeps
 * its prefix (else the oldest), the others get the first free suffix
 * (« DEV2 », « DEV3 »…). Plain columns: the partial index is usable under
 * RLS. Run per tenant (FORCE RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $companies = DB::table('companies')->whereNull('deleted_at')
                ->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get(['id', 'quote_prefix']);
            $taken = [];
            foreach ($companies as $company) {
                $prefix = $company->quote_prefix;
                if (in_array($prefix, $taken, true)) {
                    $n = 2;
                    do {
                        $candidate = substr($prefix, 0, 10 - strlen((string) $n)).$n;
                        $n++;
                    } while (in_array($candidate, $taken, true) || $companies->contains('quote_prefix', $candidate));
                    DB::table('companies')->where('id', $company->id)->update(['quote_prefix' => $candidate]);
                    $prefix = $candidate;
                }
                $taken[] = $prefix;
            }
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);

        DB::statement('create unique index companies_quote_prefix_unique on companies (tenant_id, quote_prefix) where deleted_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists companies_quote_prefix_unique');
    }
};
