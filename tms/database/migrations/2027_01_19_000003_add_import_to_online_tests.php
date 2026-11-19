<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A test drafted from a PDF in the background: importing, or failed with a fixed French reason. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_tests', function (Blueprint $table) {
            $table->string('import_status', 10)->nullable()->after('time_limit_minutes');
            $table->string('import_error', 300)->nullable()->after('import_status');
        });
    }

    public function down(): void
    {
        Schema::table('online_tests', function (Blueprint $table) {
            $table->dropColumn(['import_status', 'import_error']);
        });
    }
};
