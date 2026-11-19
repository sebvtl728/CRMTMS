<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The library holds two kinds of documents: brochures (plaquettes, sent to
 * prospects and with quotes) and positioning tests (sent to candidates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brochures', function (Blueprint $table) {
            $table->string('kind', 20)->default('brochure')->after('title');
        });
        DB::statement("ALTER TABLE brochures ADD CONSTRAINT brochures_kind_check CHECK (kind IN ('brochure', 'positioning_test'))");
    }

    public function down(): void
    {
        Schema::table('brochures', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
