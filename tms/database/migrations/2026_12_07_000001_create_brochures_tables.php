<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brochure library of the catalogue: a brochure (title) holds
     * versions of its PDF (never erased: a sent version stays readable) and is
     * attached to trainings and/or DAS. current_version_id is added once
     * brochure_versions exists (circular reference).
     */
    public function up(): void
    {
        Schema::create('brochures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->uuid('current_version_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'title']);
        });

        Schema::create('brochure_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('brochure_id')->constrained('brochures')->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['brochure_id', 'created_at']);
        });

        Schema::table('brochures', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('brochure_versions')->nullOnDelete();
        });

        Schema::create('brochure_trainings', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('brochure_id')->constrained('brochures')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->primary(['brochure_id', 'training_id']);
            $table->index('training_id');
        });

        Schema::create('brochure_das', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('brochure_id')->constrained('brochures')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->primary(['brochure_id', 'das_id']);
            $table->index('das_id');
        });

        foreach (['brochures', 'brochure_versions', 'brochure_trainings', 'brochure_das'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        DB::statement('alter table brochures drop constraint if exists brochures_current_version_id_foreign');
        Schema::dropIfExists('brochure_das');
        Schema::dropIfExists('brochure_trainings');
        Schema::dropIfExists('brochure_versions');
        Schema::dropIfExists('brochures');
    }
};
