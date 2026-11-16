<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2a: the education level of a trainee (reference list education_level),
 * next to the existing professional_situation_id (the trainee's employment
 * status: no second list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignUuid('education_level_id')->nullable()->constrained('reference_items');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('education_level_id');
        });
    }
};
