<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Paie directement l'organisme » (0.9): the funder pays the organisme
     * and receives its own invoice for its share. False for every existing
     * funding: nothing changes until a user ticks it.
     */
    public function up(): void
    {
        Schema::table('fundings', function (Blueprint $table) {
            $table->boolean('pays_directly')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('fundings', function (Blueprint $table) {
            $table->dropColumn('pays_directly');
        });
    }
};
