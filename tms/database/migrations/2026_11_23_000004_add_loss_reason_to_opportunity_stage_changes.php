<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The loss reason given at the time of a move to « Perdu »: the history
     * of the fiche shows each loss with its own reason, even after the
     * opportunity was reopened or lost again for another reason.
     */
    public function up(): void
    {
        Schema::table('opportunity_stage_changes', function (Blueprint $table) {
            $table->foreignUuid('loss_reason_id')->nullable()->constrained('reference_items');
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_stage_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loss_reason_id');
        });
    }
};
