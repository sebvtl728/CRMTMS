<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online positioning tests: a draft (sections → questions, json), the
     * DAS and trainings it serves, an optional time limit, and the versions
     * frozen at each publication (a candidate always answers a frozen one).
     */
    public function up(): void
    {
        Schema::create('online_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->jsonb('draft_content');
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->uuid('current_version_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Rls::enable('online_tests');

        Schema::create('online_test_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('online_test_id')->constrained('online_tests')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->jsonb('content');
            $table->unsignedInteger('total_points');
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['online_test_id', 'number']);
        });
        Rls::enable('online_test_versions');

        Schema::create('online_test_das', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('online_test_id')->constrained('online_tests')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->primary(['online_test_id', 'das_id']);
        });
        Rls::enable('online_test_das');

        Schema::create('online_test_trainings', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('online_test_id')->constrained('online_tests')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->primary(['online_test_id', 'training_id']);
        });
        Rls::enable('online_test_trainings');
    }

    public function down(): void
    {
        Schema::dropIfExists('online_test_trainings');
        Schema::dropIfExists('online_test_das');
        Schema::dropIfExists('online_test_versions');
        Schema::dropIfExists('online_tests');
    }
};
