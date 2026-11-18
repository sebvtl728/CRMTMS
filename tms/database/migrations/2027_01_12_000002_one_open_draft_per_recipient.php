<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One open draft per recipient of a dossier (0.9): InvoiceDrafts checks
     * it under an advisory lock of the dossier; this index is the backstop.
     * recipient_key is a stored generated column, compared by « = ».
     */
    public function up(): void
    {
        DB::statement("create unique index invoices_one_open_draft on invoices (dossier_id, recipient_key) where status = 'draft'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists invoices_one_open_draft');
    }
};
