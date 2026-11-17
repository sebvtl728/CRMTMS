<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SHA-256 (hex) of the archived PDF of a validated invoice or credit
     * note (0.9): part of its hashed content and copied into its journal
     * row, so tms:finance:verify detects an archive replaced on the disk.
     */
    public function up(): void
    {
        DB::statement('alter table invoices add column pdf_sha256 char(64)');
        DB::statement('alter table invoice_journal add column pdf_sha256 char(64)');
    }

    public function down(): void
    {
        DB::statement('alter table invoice_journal drop column if exists pdf_sha256');
        DB::statement('alter table invoices drop column if exists pdf_sha256');
    }
};
