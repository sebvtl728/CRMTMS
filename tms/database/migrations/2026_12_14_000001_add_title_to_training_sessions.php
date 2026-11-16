<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The optional title of a session (« Commis de cuisine – entrée du 30
     * novembre 2026 »), shown wherever the session appears; without one, the
     * session reads « <formation> (S-…) » (TrainingSession::label()).
     */
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->string('title', 150)->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
