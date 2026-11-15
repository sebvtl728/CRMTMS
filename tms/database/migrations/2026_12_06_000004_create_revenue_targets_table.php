<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revenue targets of a DAS for a month, a quarter or a year (the exact
     * period type: a quarter target is not the sum of its months).
     * period_start is the first day of the period. A DAS holding a target
     * cannot be deleted from the reference list.
     */
    public function up(): void
    {
        Schema::create('revenue_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->string('period_type', 10);
            $table->date('period_start');
            $table->decimal('amount', 12, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'das_id', 'period_type', 'period_start']);
            $table->index('das_id');
        });

        DB::statement("alter table revenue_targets add constraint revenue_targets_period_type_check check (period_type in ('month', 'quarter', 'year'))");
        DB::statement('alter table revenue_targets add constraint revenue_targets_amount_check check (amount >= 0)');

        Rls::enable('revenue_targets');
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_targets');
    }
};
