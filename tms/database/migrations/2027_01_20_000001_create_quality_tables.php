<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Qualité: the organisme's work on each Qualiopi indicator (one
     * row once touched; without a row: applicable, to do) and its proofs.
     */
    public function up(): void
    {
        Schema::create('quality_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('referential_version', 10);
            $table->unsignedSmallInteger('number');
            $table->boolean('applicable')->default(true);
            $table->string('not_applicable_reason', 2000)->nullable();
            $table->string('status', 15)->default('todo');
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('review_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'referential_version', 'number']);
        });
        DB::statement("ALTER TABLE quality_indicators ADD CONSTRAINT quality_indicators_status_check CHECK (status IN ('todo', 'in_progress', 'ready', 'to_review'))");
        DB::statement('ALTER TABLE quality_indicators ADD CONSTRAINT quality_indicators_reason_check CHECK (applicable OR not_applicable_reason IS NOT NULL)');
        Rls::enable('quality_indicators');

        Schema::create('quality_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('referential_version', 10);
            $table->unsignedSmallInteger('number');
            $table->string('kind', 10);
            $table->string('title', 200);
            $table->string('url', 2000)->nullable();
            $table->text('note')->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'referential_version', 'number']);
        });
        DB::statement("ALTER TABLE quality_proofs ADD CONSTRAINT quality_proofs_kind_check CHECK (kind IN ('file', 'link', 'note'))");
        Rls::enable('quality_proofs');
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_proofs');
        Schema::dropIfExists('quality_indicators');
    }
};
