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
        // One row of an imported file: outcome, messages and the fiches it touched.
        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('line');
            // Raw values of the file row: personal data, never journaled, purged
            // with the uploaded file (30 days) and by the anonymisation.
            $table->jsonb('source')->nullable();
            $table->string('status', 10);
            $table->jsonb('messages')->default('[]');
            $table->string('subject_type', 30)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->boolean('created_subject')->default(false);
            // Every fiche created by the row ([{type, id}]), e.g. entreprise +
            // person + affiliation: what an undo removes.
            $table->jsonb('created_refs')->default('[]');

            $table->index(['batch_id', 'line']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });

        DB::statement("alter table import_rows add constraint import_rows_status_check check (status in ('created','updated','skipped','error'))");
        // Finding the rows that mention a fiche (anonymisation, undo).
        DB::statement('create index import_rows_created_refs_gin on import_rows using gin (created_refs jsonb_path_ops)');

        Rls::enable('import_rows');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
