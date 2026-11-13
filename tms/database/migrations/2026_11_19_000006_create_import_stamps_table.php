<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each import did to each fiche, as plain indexed columns.
     *
     * The same facts live in import_rows.created_refs / touched_refs, but a
     * jsonb @> lookup is not leakproof: under FORCE RLS its GIN index is
     * never used, so a per-fiche lookup scanned the organisme's whole import
     * history (quadratic re-import). Equality on uuid / text is leakproof:
     * (tenant_id, subject_type, subject_id) is used by the planner.
     */
    public function up(): void
    {
        Schema::create('import_stamps', function (Blueprint $table) {
            $table->id(); // insertion order: the latest stamp of a fiche wins
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            // updated_at the import left on the fiche (to its stored precision).
            $table->string('stamped_at', 32)->nullable();
            $table->boolean('created')->default(false);
            // The batch was undone: its stamps no longer count.
            $table->boolean('batch_undone')->default(false);
            // When this undo trashed the fiche (a re-import may restore it).
            $table->timestampTz('trashed_by_undo_at')->nullable();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index('batch_id');
        });

        Rls::enable('import_stamps');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_stamps');
    }
};
