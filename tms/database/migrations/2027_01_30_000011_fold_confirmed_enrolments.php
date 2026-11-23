<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * « Confirmé » triggered nothing (an enrolment counts the same either way)
 * and confused: the screens no longer offer it, the confirmed enrolments
 * become « Inscrit » again. Per organisme (FORCE RLS). No way back: down()
 * leaves them enrolled.
 */
return new class extends Migration
{
    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::table('enrolments')->where('status', 'confirmed')->update(['status' => 'enrolled']);
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void {}
};
