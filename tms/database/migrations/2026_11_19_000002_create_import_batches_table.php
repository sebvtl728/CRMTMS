<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target', 20);
            $table->foreignUuid('profile_id')->nullable()->constrained('import_profiles')->nullOnDelete();
            $table->string('original_name');
            // Uploaded file (private disk, tenants/<id>/imports/...); null once purged.
            $table->string('stored_path')->nullable();
            $table->string('status', 20)->default('uploaded');
            // strategy (skip|merge|create), source_system, forced delimiter / encoding.
            $table->jsonb('options')->default('{}');
            $table->jsonb('field_map')->nullable();
            $table->jsonb('value_map')->nullable();
            // Summary of the last simulation (counters, warnings, unknown values).
            $table->jsonb('simulation')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('errors')->default(0);
            // Last file row handled (run) / last import row handled (undo): lets a job resume.
            $table->unsignedInteger('cursor_line')->default(0);
            $table->string('report_path')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement("alter table import_batches add constraint import_batches_target_check check (target in ('organisations','people','affiliations','notes'))");
        DB::statement("alter table import_batches add constraint import_batches_status_check check (status in ('uploaded','simulated','running','done','failed','undoing','undone'))");

        Rls::enable('import_batches');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
