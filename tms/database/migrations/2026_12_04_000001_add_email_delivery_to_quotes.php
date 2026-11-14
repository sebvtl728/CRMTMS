<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The e-mail of a sent (or reminded) quote, sent by a queued job:
     * the last message (to, cc, subject, body, kind) so that it can be sent
     * again, and its delivery state. email_error is a fixed French message
     * (an SMTP code at most), never the server's text.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->jsonb('email_message')->nullable();
            $table->string('email_status', 10)->nullable();
            $table->timestampTz('email_sent_at')->nullable();
            $table->string('email_error', 255)->nullable();
        });

        DB::statement("alter table quotes add constraint quotes_email_status_check check (email_status in ('pending', 'sent', 'failed'))");
    }

    public function down(): void
    {
        DB::statement('alter table quotes drop constraint if exists quotes_email_status_check');
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['email_message', 'email_status', 'email_sent_at', 'email_error']);
        });
    }
};
