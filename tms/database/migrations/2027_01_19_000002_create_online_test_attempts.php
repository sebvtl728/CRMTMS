<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A link may lead to an online test (a published version); the
     * candidate's attempt (one per link): answers saved as typed, traces,
     * automatic and manual scores.
     */
    public function up(): void
    {
        Schema::table('positioning_sendings', function (Blueprint $table) {
            $table->foreignUuid('online_test_version_id')->nullable()->after('candidature_id')->constrained('online_test_versions');
        });

        Schema::create('online_test_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('positioning_sending_id')->unique()->constrained('positioning_sendings')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->foreignUuid('online_test_version_id')->constrained('online_test_versions');
            $table->timestampTz('started_at');
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->jsonb('answers')->default('{}');
            $table->jsonb('events')->default('[]');
            $table->unsignedInteger('auto_score')->nullable();
            $table->jsonb('manual_scores')->default('{}');
            $table->unsignedInteger('score')->nullable();
            $table->unsignedInteger('max_score');
            $table->timestamps();
            $table->index('candidature_id');
        });
        Rls::enable('online_test_attempts');
    }

    public function down(): void
    {
        Schema::dropIfExists('online_test_attempts');
        Schema::table('positioning_sendings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('online_test_version_id');
        });
    }
};
