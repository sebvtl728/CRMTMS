<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Positioning tests sent to a candidate: the e-mail, the tests (versions
     * of the library) and the personal upload link (sha256 of the token
     * only), valid until expires_at, revoked by a newer sending, used once.
     */
    public function up(): void
    {
        Schema::create('positioning_sendings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->string('email', 254)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->string('email_status', 10)->default('pending');
            $table->string('email_error', 300)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['candidature_id', 'created_at']);
        });
        DB::statement("ALTER TABLE positioning_sendings ADD CONSTRAINT positioning_sendings_status_check CHECK (email_status IN ('pending', 'sent', 'failed'))");
        Rls::enable('positioning_sendings');

        Schema::create('positioning_sending_versions', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('positioning_sending_id')->constrained('positioning_sendings')->cascadeOnDelete();
            $table->foreignUuid('brochure_version_id')->constrained('brochure_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['positioning_sending_id', 'brochure_version_id']);
            $table->index('brochure_version_id');
        });
        Rls::enable('positioning_sending_versions');
    }

    public function down(): void
    {
        Schema::dropIfExists('positioning_sending_versions');
        Schema::dropIfExists('positioning_sendings');
    }
};
