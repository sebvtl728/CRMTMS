<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The collective agreements (IDCC) an OPCO covers: its funding is proposed for the dossiers of their entreprises. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->jsonb('covered_idcc')->nullable()->after('idcc');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('covered_idcc');
        });
    }
};
