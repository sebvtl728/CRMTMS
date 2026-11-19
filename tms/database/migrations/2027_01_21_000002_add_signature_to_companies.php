<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The signature and stamp a company prints on its training documents (0.10), private disk. */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('signature_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};
