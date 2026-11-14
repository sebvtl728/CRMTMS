<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an imported training comes from (software and its identifier
     * there), so that a re-import updates it instead of creating it again.
     * Unique per organisme among the trainings not deleted (plain partial
     * index, no expression: the table is under RLS). The imports accept the
     * new target « trainings » (Crm ImportTarget::Trainings).
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('source_system', 50)->nullable();
            $table->string('source_id', 255)->nullable();
        });

        DB::statement('create unique index trainings_source_unique on trainings (tenant_id, source_system, source_id) where deleted_at is null and source_system is not null and source_id is not null');

        foreach (['import_batches', 'import_profiles'] as $table) {
            DB::statement("alter table {$table} drop constraint {$table}_target_check");
            DB::statement("alter table {$table} add constraint {$table}_target_check check (target in ('organisations','people','affiliations','notes','trainings'))");
        }
    }

    public function down(): void
    {
        foreach (['import_batches', 'import_profiles'] as $table) {
            DB::statement("alter table {$table} drop constraint {$table}_target_check");
            DB::statement("alter table {$table} add constraint {$table}_target_check check (target in ('organisations','people','affiliations','notes'))");
        }
        DB::statement('drop index if exists trainings_source_unique');
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['source_system', 'source_id']);
        });
    }
};
