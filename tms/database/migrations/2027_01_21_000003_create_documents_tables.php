<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Documents (0.10): the training documents issued from a dossier,
     * frozen once issued (archived PDF + SHA-256), and their numbering
     * DOC-<année de Paris>-<NNNNN>, continuous per organisme and year.
     *
     * documents_immutable: a document is never deleted; only its life
     * changes (status, sending, cancellation, updated_at) and its recipient
     * snapshot (erased by the GDPR anonymisation); everything printed on it
     * stays as issued.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 40);
            $table->foreignUuid('dossier_id')->index()->constrained('dossiers');
            $table->foreignUuid('enrolment_id')->nullable()->index()->constrained('enrolments');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->string('number', 30);
            $table->jsonb('recipient_snapshot');
            $table->string('pdf_path');
            $table->char('pdf_sha256', 64);
            $table->string('status', 20)->default('issued');
            $table->timestampTz('issued_at');
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->string('email_status', 10)->nullable();
            $table->string('email_error_code', 40)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('convention', 'contract', 'convocation', 'completion_certificate', 'realisation_certificate'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('issued', 'sent', 'signature_pending', 'signed', 'cancelled'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_email_status_check CHECK (email_status IS NULL OR email_status IN ('pending', 'sent', 'failed'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_cancel_check CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))");
        Rls::enable('documents');

        DB::statement(<<<'SQL'
            create or replace function documents_immutable() returns trigger language plpgsql as $$
            declare
                living constant text[] := array['status', 'sent_at', 'email_status', 'email_error_code', 'cancelled_at', 'cancelled_by',
                    'cancel_reason', 'recipient_snapshot', 'updated_at'];
            begin
                if tg_op = 'DELETE' then
                    raise exception 'document % is issued: immutable', old.number;
                end if;
                if old.status = 'cancelled' and new.status <> 'cancelled' then
                    raise exception 'document % is cancelled', old.number;
                end if;
                if (to_jsonb(new) - living) is distinct from (to_jsonb(old) - living) then
                    raise exception 'document % is issued: immutable', old.number;
                end if;
                return new;
            end;
            $$
        SQL);
        DB::statement('create trigger documents_immutable before update or delete on documents for each row execute function documents_immutable()');

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->integer('last_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'year']);
        });
        Rls::enable('document_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        DB::statement('drop trigger if exists documents_immutable on documents');
        DB::statement('drop function if exists documents_immutable()');
        Schema::dropIfExists('documents');
    }
};
