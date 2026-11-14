<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A training of the organisme's catalogue (module Catalogue), attached to
     * one DAS. Prices are HT per trainee. The code is stored upper-cased and
     * unique among the trainings not deleted (a plain partial index: no
     * expression index on an RLS table).
     */
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('title', 200);
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->decimal('duration_hours', 6, 2)->nullable();
            $table->decimal('duration_days', 5, 2)->nullable();
            $table->text('audience')->nullable();
            $table->text('prerequisites')->nullable();
            $table->decimal('price_company', 12, 2)->nullable();
            $table->decimal('price_individual', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'das_id']);
            $table->index(['tenant_id', 'title']);
        });

        DB::statement('alter table trainings add constraint trainings_amounts_check check ((price_company is null or price_company >= 0) and (price_individual is null or price_individual >= 0))');
        DB::statement('alter table trainings add constraint trainings_durations_check check ((duration_hours is null or duration_hours >= 0) and (duration_days is null or duration_days >= 0))');
        DB::statement('create unique index trainings_code_unique on trainings (tenant_id, code) where deleted_at is null and code is not null');

        Rls::enable('trainings');
    }

    public function down(): void
    {
        Schema::dropIfExists('trainings');
    }
};
