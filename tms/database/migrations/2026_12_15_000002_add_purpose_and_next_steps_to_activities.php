<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Motif » (why the call or meeting) and « Suite à donner » (what comes
     * next) of an action: optional texts, personal (journaled without value,
     * cleared by the anonymisation). The motif goes to Outlook, the next
     * steps never do.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->text('purpose')->nullable()->after('subject');
            $table->text('next_steps')->nullable()->after('report');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'next_steps']);
        });
    }
};
