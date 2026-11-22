<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Automatic reminders of a pending online signature (2026-11-22). */
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestampTz('last_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn(['reminders_sent', 'last_sent_at']);
        });
    }
};
