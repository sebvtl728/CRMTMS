<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every credit note corrects an invoice (0.9). NOT VALID: checked on
     * every row written from now on, without failing on a draft prepared
     * before this rule.
     */
    public function up(): void
    {
        DB::statement("alter table invoices add constraint invoices_credit_note_corrects_check check (kind <> 'credit_note' or corrected_invoice_id is not null) not valid");
    }

    public function down(): void
    {
        DB::statement('alter table invoices drop constraint if exists invoices_credit_note_corrects_check');
    }
};
