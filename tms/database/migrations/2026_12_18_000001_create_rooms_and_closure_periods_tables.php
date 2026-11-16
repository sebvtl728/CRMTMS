<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rooms and closure periods of the organisme (0.8.0, « Réglages » of the
     * sessions). A room used by a séance is never deleted (deactivated
     * instead, Rooms::registerUsage); a closure period is a range of Paris
     * days, bounds included, that the séance generator skips.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->integer('capacity')->nullable();
            $table->string('address', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('alter table rooms add constraint rooms_capacity_check check (capacity is null or capacity > 0)');
        Rls::enable('rooms');

        Schema::create('closure_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('label', 120);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_on']);
        });
        DB::statement('alter table closure_periods add constraint closure_periods_dates_check check (ends_on >= starts_on)');
        Rls::enable('closure_periods');
    }

    public function down(): void
    {
        Schema::dropIfExists('closure_periods');
        Schema::dropIfExists('rooms');
    }
};
