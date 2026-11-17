<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * calendar_link_events carries the Outlook copies of any registered
     * source (0.8.1): « crm.activity » (the CRM actions, every existing row)
     * and « sessions.seance » (the séances of a trainer). activity_id becomes
     * source_id (still no foreign key: the source module is read through its
     * public service only).
     */
    public function up(): void
    {
        Schema::table('calendar_link_events', function (Blueprint $table) {
            $table->string('source', 40)->default('crm.activity');
            $table->dropUnique(['calendar_link_id', 'activity_id']);
            $table->dropIndex(['activity_id']);
        });
        Schema::table('calendar_link_events', function (Blueprint $table) {
            $table->renameColumn('activity_id', 'source_id');
        });
        Schema::table('calendar_link_events', function (Blueprint $table) {
            $table->unique(['calendar_link_id', 'source', 'source_id']);
            $table->index(['source', 'source_id']);
        });
    }

    /**
     * Back to the actions only: the copies of other sources are forgotten
     * (organisme by organisme: the table is under row level security).
     */
    public function down(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::table('calendar_link_events')->where('source', '<>', 'crm.activity')->delete();
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);

        Schema::table('calendar_link_events', function (Blueprint $table) {
            $table->dropUnique(['calendar_link_id', 'source', 'source_id']);
            $table->dropIndex(['source', 'source_id']);
        });
        Schema::table('calendar_link_events', function (Blueprint $table) {
            $table->renameColumn('source_id', 'activity_id');
        });
        Schema::table('calendar_link_events', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->unique(['calendar_link_id', 'activity_id']);
            $table->index('activity_id');
        });
    }
};
