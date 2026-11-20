<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tests only: the sandbox reset runs on the owner connection in real
     * life; the tests run it on their own (transactional) connection, whose
     * role is given TRUNCATE here so the test transaction is never blocked.
     */
    public function up(): void
    {
        DB::statement('GRANT TRUNCATE ON ALL TABLES IN SCHEMA public TO tms_app');
    }

    public function down(): void {}
};
