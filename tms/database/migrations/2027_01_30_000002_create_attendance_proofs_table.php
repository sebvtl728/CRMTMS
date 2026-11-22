<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proofs of an absence (medical certificate, summons…): a PDF or an image
     * per séance and trainee, on the private disk under
     * tenants/<tenant_id>/attendance_proofs/. Personal data: erased with the
     * trainee's anonymisation.
     */
    public function up(): void
    {
        Schema::create('attendance_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('seance_id')->constrained('seances');
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['seance_id', 'enrolment_id']);
            $table->index('enrolment_id');
        });
        Rls::enable('attendance_proofs');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_proofs');
    }
};
