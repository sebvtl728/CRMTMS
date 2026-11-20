<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lot 1e: the action result « Réussi » (code reussi) of the existing
     * organismes counts a call as answered (meta.reached), as in the CAP
     * FORMATION references of a new organisme. Tenant by tenant (RLS).
     */
    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::statement("update reference_items set meta = coalesce(meta, '{}'::jsonb) || '{\"reached\": true}'::jsonb
                where list = 'action_result' and code = 'reussi' and tenant_id = ?", [$tenantId]);
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        // The flag stays harmless without the dashboards.
    }
};
