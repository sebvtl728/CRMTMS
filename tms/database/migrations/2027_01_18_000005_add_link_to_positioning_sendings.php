<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The link of a positioning test can be copied from the candidature: its
 * token is also kept encrypted (APP_KEY) while the lookup stays on the
 * sha256; a link may be generated without any e-mail (email_status none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positioning_sendings', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });
        DB::statement('ALTER TABLE positioning_sendings DROP CONSTRAINT positioning_sendings_status_check');
        DB::statement("ALTER TABLE positioning_sendings ADD CONSTRAINT positioning_sendings_status_check CHECK (email_status IN ('pending', 'sent', 'failed', 'none'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE positioning_sendings SET email_status = 'sent' WHERE email_status = 'none'");
        DB::statement('ALTER TABLE positioning_sendings DROP CONSTRAINT positioning_sendings_status_check');
        DB::statement("ALTER TABLE positioning_sendings ADD CONSTRAINT positioning_sendings_status_check CHECK (email_status IN ('pending', 'sent', 'failed'))");
        Schema::table('positioning_sendings', function (Blueprint $table) {
            $table->dropColumn('token_encrypted');
        });
    }
};
