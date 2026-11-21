<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online attendance signing (émargement en ligne): a slot per session,
     * Paris day and half-day (morning / afternoon, cut at 13:00) opened by
     * the trainer behind a shared link (only its SHA-256 kept), the drawn
     * signatures of the trainees, and the archived signed sheets (PDF per
     * session and month, SHA-256). attendances.source marks the rows written
     * from the signatures (null = entered by hand).
     */
    public function up(): void
    {
        Schema::create('attendance_sign_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions');
            $table->date('date');
            $table->string('half', 10);
            $table->string('status', 10);
            $table->char('token_hash', 64)->nullable()->unique();
            // The shared token, encrypted (APP_KEY): shown again to the trainer only (QR code); lookups use token_hash.
            $table->text('token_encrypted')->nullable();
            $table->timestampTz('opened_at');
            $table->foreignUuid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trainer_name', 200)->nullable();
            $table->string('validator_role', 10)->nullable();
            $table->string('trainer_signature_path', 300)->nullable();
            $table->char('trainer_signature_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'training_session_id', 'date', 'half']);
        });
        DB::statement("alter table attendance_sign_slots add constraint attendance_sign_slots_half check (half in ('morning', 'afternoon'))");
        DB::statement("alter table attendance_sign_slots add constraint attendance_sign_slots_status check (status in ('open', 'closed', 'validated'))");
        DB::statement("alter table attendance_sign_slots add constraint attendance_sign_slots_role check (validator_role is null or validator_role in ('trainer', 'manager'))");
        Rls::enable('attendance_sign_slots');

        Schema::create('attendance_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('slot_id')->constrained('attendance_sign_slots');
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->string('drawing_path', 300)->nullable();
            $table->char('drawing_sha256', 64)->nullable();
            $table->timestampTz('signed_at');
            $table->string('source', 40)->default('online');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamps();

            $table->unique(['slot_id', 'enrolment_id']);
            $table->index('enrolment_id');
        });
        Rls::enable('attendance_signatures');

        Schema::create('attendance_signed_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions');
            $table->date('month');
            $table->string('path', 300);
            $table->char('pdf_sha256', 64);
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['training_session_id', 'month']);
        });
        Rls::enable('attendance_signed_sheets');

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::dropIfExists('attendance_signed_sheets');
        Schema::dropIfExists('attendance_signatures');
        Schema::dropIfExists('attendance_sign_slots');
    }
};
