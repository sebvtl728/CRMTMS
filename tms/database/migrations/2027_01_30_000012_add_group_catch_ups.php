<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A catch-up link may cover several half-days of one trainee (one e-mail, one code, one drawing). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_catch_up_links', function (Blueprint $table): void {
            $table->jsonb('extra_slot_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_catch_up_links', function (Blueprint $table): void {
            $table->dropColumn('extra_slot_ids');
        });
    }
};
