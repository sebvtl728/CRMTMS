<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hours of a training in the centre and in the company (alternance,
 * placements): the total stays duration_hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->decimal('centre_hours', 6, 2)->nullable();
            $table->decimal('company_hours', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['centre_hours', 'company_hours']);
        });
    }
};
