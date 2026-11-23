<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The entreprise form shows the commercial status (prospect, client,
 * inactif) only for a « cliente » role (meta.client) or no role: the
 * « Entreprise cliente » type of each organisme gets the flag. Per organisme
 * (FORCE RLS); idempotent. down() leaves the flag (harmless).
 */
return new class extends Migration
{
    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::update(
                "update reference_items set meta = coalesce(meta, '{}'::jsonb) || '{\"client\": true}'::jsonb
                 where list = 'organisation_type' and code = 'entreprise-cliente' and coalesce((meta->>'client')::boolean, false) = false"
            );
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void {}
};
