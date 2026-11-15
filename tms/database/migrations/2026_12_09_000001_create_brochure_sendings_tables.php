<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Envoyer une plaquette » from a fiche: one row per e-mail (the fiche
     * it was sent from, the opportunity when sent from one, the recipient,
     * the address and the text as sent, the delivery status) and the exact
     * brochure versions joined (a version is never erased, so the pivot
     * restricts). The address, subject and message are personal data.
     */
    public function up(): void
    {
        Schema::create('brochure_sendings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignUuid('recipient_person_id')->constrained('people')->cascadeOnDelete();
            $table->string('recipient_email', 254)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->string('email_status', 10)->default('pending');
            $table->string('email_error', 300)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'created_at']);
            $table->index(['person_id', 'created_at']);
            $table->index(['recipient_person_id', 'created_at']);
            $table->index('opportunity_id');
        });
        DB::statement('alter table brochure_sendings add constraint brochure_sendings_fiche_check check ((organisation_id is not null)::int + (person_id is not null)::int = 1)');
        DB::statement("alter table brochure_sendings add constraint brochure_sendings_status_check check (email_status in ('pending', 'sent', 'failed'))");

        Schema::create('brochure_sending_versions', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('brochure_sending_id')->constrained('brochure_sendings')->cascadeOnDelete();
            $table->foreignUuid('brochure_version_id')->constrained('brochure_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['brochure_sending_id', 'brochure_version_id']);
            $table->index('brochure_version_id');
        });

        Rls::enable('brochure_sendings');
        Rls::enable('brochure_sending_versions');
    }

    public function down(): void
    {
        Schema::dropIfExists('brochure_sending_versions');
        Schema::dropIfExists('brochure_sendings');
    }
};
