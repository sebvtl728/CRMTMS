<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catch-up of the online signing (2026-11-21): a signature has a kind
     * (on time, catch-up on site, catch-up remote) and, for a catch-up, a
     * confirmation or a refusal by an attendance manager; a half-day carries
     * its on-site catch-up link (SHA-256, until the end of the opening day);
     * a remote catch-up is a personal link per half-day and trainee, with an
     * e-mailed code (HMAC), one active link at a time.
     */
    public function up(): void
    {
        Schema::table('attendance_signatures', function (Blueprint $table) {
            $table->string('kind', 20)->default('on_time');
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('refused_at')->nullable();
            $table->foreignUuid('refused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refusal_reason', 300)->nullable();
        });
        DB::statement("alter table attendance_signatures add constraint attendance_signatures_kind check (kind in ('on_time', 'catch_up_onsite', 'catch_up_remote'))");
        DB::statement("alter table attendance_signatures add constraint attendance_signatures_review check (kind <> 'on_time' or (confirmed_at is null and refused_at is null))");
        DB::statement('alter table attendance_signatures add constraint attendance_signatures_one_review check (confirmed_at is null or refused_at is null)');

        Schema::table('attendance_sign_slots', function (Blueprint $table) {
            $table->char('catch_up_token_hash', 64)->nullable()->unique();
            $table->timestampTz('catch_up_opened_at')->nullable();
            $table->foreignUuid('catch_up_opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('catch_up_expires_at')->nullable();
        });

        Schema::create('attendance_catch_up_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('slot_id')->constrained('attendance_sign_slots');
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->string('email', 254)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->timestampTz('code_expires_at')->nullable();
            $table->smallInteger('code_attempts')->default(0);
            $table->timestampTz('code_sent_at')->nullable();
            $table->string('email_status', 20)->nullable();
            $table->string('email_error_code', 40)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('enrolment_id');
        });
        DB::statement('create unique index attendance_catch_up_links_one_active on attendance_catch_up_links (slot_id, enrolment_id) where used_at is null and revoked_at is null');
        Rls::enable('attendance_catch_up_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_catch_up_links');
        Schema::table('attendance_sign_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catch_up_opened_by');
            $table->dropColumn(['catch_up_token_hash', 'catch_up_opened_at', 'catch_up_expires_at']);
        });
        DB::statement('alter table attendance_signatures drop constraint attendance_signatures_one_review');
        DB::statement('alter table attendance_signatures drop constraint attendance_signatures_review');
        DB::statement('alter table attendance_signatures drop constraint attendance_signatures_kind');
        Schema::table('attendance_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('refused_by');
            $table->dropColumn(['kind', 'confirmed_at', 'refused_at', 'refusal_reason']);
        });
    }
};
