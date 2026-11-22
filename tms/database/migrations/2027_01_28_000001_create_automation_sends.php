<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automatic sends (2026-11-22): each one is claimed once by its unique
     * key (kind, subject, occurrence), so a second run, a second server or a
     * catch-up never sends it again. Ids only, no personal data.
     */
    public function up(): void
    {
        Schema::create('automation_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->uuid('subject_id');
            $table->string('occurrence', 20);
            $table->timestampTz('sent_at');
            $table->unique(['tenant_id', 'kind', 'subject_id', 'occurrence']);
        });
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder'))");
        Rls::enable('automation_sends');
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_sends');
    }
};
