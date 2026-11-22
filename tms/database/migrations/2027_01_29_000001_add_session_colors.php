<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Colour of each session (SessionColors), the existing ones coloured in turn by creation order, organisme by organisme. */
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->string('color', 7)->nullable();
        });
        $palette = "array['#2563eb','#16a34a','#dc2626','#9333ea','#ea580c','#0891b2','#ca8a04','#db2777','#4f46e5','#059669','#b45309','#475569']";
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::statement("update training_sessions t set color = ({$palette})[((s.rn - 1) % 12) + 1]
                from (select id, row_number() over (order by created_at, id) as rn from training_sessions) s where s.id = t.id");
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
