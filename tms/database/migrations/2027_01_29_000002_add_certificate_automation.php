<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Automatic certificates (2026-11-22): a fourth kind of automatic send. */
    public function up(): void
    {
        DB::statement('ALTER TABLE automation_sends DROP CONSTRAINT automation_sends_kind_check');
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder', 'certificate'))");
    }

    public function down(): void
    {
        // Under FORCE RLS even for the owner: organisme by organisme.
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::statement("DELETE FROM automation_sends WHERE kind = 'certificate'");
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
        DB::statement('ALTER TABLE automation_sends DROP CONSTRAINT automation_sends_kind_check');
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder'))");
    }
};
