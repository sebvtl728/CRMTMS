<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core\Signatures (0.10): online signature of a PDF (a document, a
     * quote…) by a link and a code sent by e-mail; simple electronic
     * signature with a proof page. Only the hashes of the link and the code
     * are kept; signer data are personal (erased by the anonymisation).
     */
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('title', 200);
            $table->string('pdf_path');
            $table->char('pdf_sha256', 64);
            $table->string('signer_name', 200)->nullable();
            $table->string('signer_email', 254)->nullable();
            $table->uuid('signer_person_id')->nullable()->index();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->string('status', 10)->default('pending');
            $table->char('code_hash', 64)->nullable();
            $table->timestampTz('code_expires_at')->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->timestampTz('code_sent_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 300)->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->char('signed_pdf_sha256', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE signature_requests ADD CONSTRAINT signature_requests_status_check CHECK (status IN ('pending', 'signed', 'cancelled'))");
        Rls::enable('signature_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
