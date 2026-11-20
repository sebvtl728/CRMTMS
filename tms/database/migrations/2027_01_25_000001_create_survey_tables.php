<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satisfaction questionnaires (0.11): the templates of the organisme,
     * one campaign per session, type (hot / cold) and public (trainee /
     * client) with its frozen questions and its session QR link, one
     * invitation per recipient (personal link, sha256 only) and at most one
     * response per invitation.
     */
    public function up(): void
    {
        Schema::create('survey_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('kind', 10);
            $table->string('audience', 10);
            $table->jsonb('questions');
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE survey_templates ADD CONSTRAINT survey_templates_kind_check CHECK (kind IN ('hot', 'cold'))");
        DB::statement("ALTER TABLE survey_templates ADD CONSTRAINT survey_templates_audience_check CHECK (audience IN ('trainee', 'client'))");
        DB::statement('CREATE UNIQUE INDEX survey_templates_one_default ON survey_templates (tenant_id, kind, audience) WHERE is_default AND deleted_at IS NULL');
        Rls::enable('survey_templates');

        Schema::create('survey_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('training_session_id');
            $table->foreignUuid('template_id')->nullable()->constrained('survey_templates')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('audience', 10);
            $table->string('title', 150);
            $table->jsonb('questions');
            $table->string('qr_token_hash', 64)->nullable()->unique();
            $table->text('qr_token_encrypted')->nullable();
            $table->timestampTz('opened_at');
            $table->date('closes_on');
            $table->foreignUuid('launched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'training_session_id', 'kind', 'audience']);
            $table->index(['tenant_id', 'training_session_id']);
        });
        DB::statement("ALTER TABLE survey_campaigns ADD CONSTRAINT survey_campaigns_kind_check CHECK (kind IN ('hot', 'cold'))");
        DB::statement("ALTER TABLE survey_campaigns ADD CONSTRAINT survey_campaigns_audience_check CHECK (audience IN ('trainee', 'client'))");
        Rls::enable('survey_campaigns');

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('survey_campaigns')->cascadeOnDelete();
            $table->uuid('enrolment_id')->nullable();
            $table->uuid('person_id');
            $table->string('email', 254)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('email_status', 10);
            $table->string('email_error', 200)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->string('answered_via', 10)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'campaign_id', 'person_id']);
            $table->index(['tenant_id', 'person_id']);
            $table->index(['tenant_id', 'enrolment_id']);
        });
        DB::statement("ALTER TABLE survey_invitations ADD CONSTRAINT survey_invitations_email_status_check CHECK (email_status IN ('pending', 'sent', 'failed', 'none'))");
        DB::statement("ALTER TABLE survey_invitations ADD CONSTRAINT survey_invitations_answered_via_check CHECK (answered_via IS NULL OR answered_via IN ('email', 'qr'))");
        Rls::enable('survey_invitations');

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invitation_id')->unique()->constrained('survey_invitations')->cascadeOnDelete();
            $table->jsonb('answers');
            $table->smallInteger('score')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE survey_responses ADD CONSTRAINT survey_responses_score_check CHECK (score IS NULL OR score BETWEEN 100 AND 500)');
        Rls::enable('survey_responses');
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_invitations');
        Schema::dropIfExists('survey_campaigns');
        Schema::dropIfExists('survey_templates');
    }
};
