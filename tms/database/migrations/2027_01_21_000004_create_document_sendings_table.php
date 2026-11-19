<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Documents (0.10): each e-mail of a document (address, subject
     * and message as typed: personal, erased by the anonymisation), its
     * state and its failure reason (a fixed French text, never the server's).
     */
    public function up(): void
    {
        Schema::create('document_sendings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('document_id')->index()->constrained('documents');
            $table->string('email', 254)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 10)->default('pending');
            $table->string('error', 300)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE document_sendings ADD CONSTRAINT document_sendings_status_check CHECK (status IN ('pending', 'sent', 'failed'))");
        Rls::enable('document_sendings');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sendings');
    }
};
