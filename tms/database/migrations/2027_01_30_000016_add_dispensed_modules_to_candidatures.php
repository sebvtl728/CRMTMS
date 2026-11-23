<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules of the training a candidate is exempted from (individual pathway
 * of the apprenticeship booklet): a list of training module ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->jsonb('dispensed_module_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->dropColumn('dispensed_module_ids');
        });
    }
};
