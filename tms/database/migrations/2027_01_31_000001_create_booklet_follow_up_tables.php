<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online follow-up of the apprenticeship booklet (spec 2026-11-24, part B):
 * the monthly pages (company / centre) with what the apprentice, the tutor
 * and the trainer typed and signed, the monthly links of the apprentice and
 * the tutor (only the SHA-256 of the token), the A / ECA / NA marks of the
 * competence grid (centre and company side) and the company visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booklet_months', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->date('month');
            $table->string('place', 10);
            $table->jsonb('apprentice_entries')->nullable();
            $table->jsonb('observations')->nullable();
            foreach (['apprentice', 'tutor'] as $signer) {
                $table->timestampTz($signer.'_signed_at')->nullable();
                $table->string($signer.'_signature_path')->nullable();
                $table->string($signer.'_signer_name')->nullable();
                $table->boolean($signer.'_paper')->default(false);
                $table->string($signer.'_ip', 45)->nullable();
                $table->string($signer.'_agent', 500)->nullable();
                $table->uuid($signer.'_link_id')->nullable();
            }
            $table->foreignUuid('visa_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('visa_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['candidature_id', 'month', 'place']);
        });
        DB::statement("alter table booklet_months add constraint booklet_months_place_check check (place in ('company', 'centre'))");
        Rls::enable('booklet_months');

        Schema::create('booklet_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->date('month');
            $table->string('signer', 12);
            $table->uuid('person_id');
            $table->string('email')->nullable();
            $table->string('signer_name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at')->nullable();
            $table->string('email_status', 10)->default('pending');
            $table->timestampTz('closed_at')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestampTz('code_expires_at')->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->timestampTz('code_sent_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['candidature_id', 'month']);
        });
        DB::statement("alter table booklet_links add constraint booklet_links_signer_check check (signer in ('apprentice', 'tutor'))");
        DB::statement('create unique index booklet_links_one_open on booklet_links (candidature_id, month, signer) where closed_at is null');
        Rls::enable('booklet_links');

        Schema::create('booklet_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->string('skill_key', 64);
            $table->string('side', 10);
            $table->string('level', 3);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['candidature_id', 'skill_key', 'side']);
        });
        DB::statement("alter table booklet_evaluations add constraint booklet_evaluations_check check (side in ('centre', 'company') and level in ('A', 'ECA', 'NA'))");
        Rls::enable('booklet_evaluations');

        Schema::create('booklet_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->date('visited_on');
            $table->text('points')->nullable();
            $table->foreignUuid('trainer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['candidature_id', 'visited_on']);
        });
        Rls::enable('booklet_visits');
    }

    public function down(): void
    {
        foreach (['booklet_visits', 'booklet_evaluations', 'booklet_links', 'booklet_months'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
