<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.9: the CPF is billed outside the TMS (through EDOF), a flag of its
 * funding type (meta.external_billing) the seeder now sets. For the
 * organismes that already exist, only the live funding type labelled
 * exactly « CPF » gets it, unless already set: a renamed or reworked item
 * is left to the organisme. Run per organisme (FORCE RLS), once by the
 * migrator: a flag the organisme removes afterwards is not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->eachTenant(function (): void {
            $items = DB::table('reference_items')->where('list', 'funding_type')->where('label', 'CPF')->whereNull('deleted_at')->get(['id', 'meta']);
            foreach ($items as $item) {
                $meta = is_string($item->meta) ? (array) json_decode($item->meta, true) : [];
                if (array_key_exists('external_billing', $meta)) {
                    continue;
                }
                DB::table('reference_items')->where('id', $item->id)->update(['meta' => json_encode([...$meta, 'external_billing' => true])]);
            }
        });
    }

    public function down(): void
    {
        $this->eachTenant(function (): void {
            foreach (DB::table('reference_items')->where('list', 'funding_type')->where('label', 'CPF')->whereNull('deleted_at')->whereNotNull('meta')->get(['id', 'meta']) as $item) {
                $meta = (array) json_decode((string) $item->meta, true);
                unset($meta['external_billing']);
                DB::table('reference_items')->where('id', $item->id)->update(['meta' => $meta === [] ? null : json_encode($meta)]);
            }
        });
    }

    private function eachTenant(callable $fn): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $fn((string) $tenantId);
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }
};
