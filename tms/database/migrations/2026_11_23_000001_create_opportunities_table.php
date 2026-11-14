<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An affaire in the pipeline, held by an entreprise OR a particulier
     * (never both, never none). The opportunity follows its fiche: no
     * merged_into_id of its own (a merge moves it to the kept fiche).
     */
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('title', 200);
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->foreignUuid('stage_id')->constrained('reference_items');
            $table->smallInteger('probability');
            $table->boolean('probability_overridden')->default(false);
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('expected_close_on')->nullable();
            $table->foreignUuid('priority_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('funding_mode_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('loss_reason_id')->nullable()->constrained('reference_items');
            $table->text('loss_comment')->nullable();
            $table->date('won_on')->nullable();
            $table->date('lost_on')->nullable();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_system', 50)->nullable();
            $table->string('source_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('organisation_id');
            $table->index('person_id');
            $table->index(['tenant_id', 'stage_id']);
            $table->index(['tenant_id', 'das_id']);
            $table->index(['tenant_id', 'responsible_user_id']);
            $table->index(['tenant_id', 'expected_close_on']);
        });

        DB::statement('alter table opportunities add constraint opportunities_account_check check ((organisation_id is null) <> (person_id is null))');
        DB::statement('alter table opportunities add constraint opportunities_probability_check check (probability between 0 and 100)');
        DB::statement('alter table opportunities add constraint opportunities_amount_check check (amount is null or amount >= 0)');
        DB::statement('alter table opportunities add column weighted_amount numeric(12,2) generated always as (round(amount * probability / 100.0, 2)) stored');
        DB::statement('create unique index opportunities_source_unique on opportunities (tenant_id, source_system, source_id) where source_system is not null and source_id is not null');

        Rls::enable('opportunities');
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
