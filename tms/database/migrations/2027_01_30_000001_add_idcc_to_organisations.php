<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IDCC of an entreprise: its collective agreement, 4 digits (« 0843 »), optional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('idcc', 4)->nullable()->after('naf_code');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('idcc');
        });
    }
};
