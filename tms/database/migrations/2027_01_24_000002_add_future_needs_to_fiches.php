<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 1e: « Besoins futurs » of a client (entreprise or person): free
     * text with the date and author of its last change. Personal for a
     * person (erased by the anonymisation, never in clear in the audit).
     */
    public function up(): void
    {
        foreach (['organisations', 'people'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->text('future_needs')->nullable();
                $t->timestampTz('future_needs_updated_at')->nullable();
                $t->foreignUuid('future_needs_updated_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['organisations', 'people'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('future_needs_updated_by');
                $t->dropColumn(['future_needs', 'future_needs_updated_at']);
            });
        }
    }
};
