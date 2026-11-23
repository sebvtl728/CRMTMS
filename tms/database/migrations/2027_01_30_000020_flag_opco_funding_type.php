<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A funding type can be paid by the OPCO of the contractant's collective
 * agreement (meta.opco): the dossier created from a quote then names that
 * OPCO as funder. For the organismes that already exist, the live funding
 * type of code « opco » gets the flag, unless the key is already set. Per
 * organisme (FORCE RLS); idempotent. down() leaves the flag (harmless).
 */
return new class extends Migration
{
    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::update(
                "update reference_items set meta = coalesce(meta, '{}'::jsonb) || '{\"opco\": true}'::jsonb
                 where list = 'funding_type' and code = 'opco' and deleted_at is null and not (coalesce(meta, '{}'::jsonb) ? 'opco')"
            );
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void {}
};
