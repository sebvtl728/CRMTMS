<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One open draft per recipient of a dossier AND per kind (0.9): an
     * invoice draft and a credit-note draft (« Créer un avoir » from a
     * validated invoice) may be open together for the same recipient.
     */
    public function up(): void
    {
        DB::statement('drop index if exists invoices_one_open_draft');
        DB::statement("create unique index invoices_one_open_draft on invoices (dossier_id, recipient_key, kind) where status = 'draft'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists invoices_one_open_draft');
        DB::statement("create unique index invoices_one_open_draft on invoices (dossier_id, recipient_key) where status = 'draft'");
    }
};
