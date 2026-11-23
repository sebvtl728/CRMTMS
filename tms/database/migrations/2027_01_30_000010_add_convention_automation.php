<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The automatic convention of a dossier created from a signed quote: one send per dossier. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE automation_sends DROP CONSTRAINT automation_sends_kind_check');
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder', 'certificate', 'convention'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM automation_sends WHERE kind = 'convention'");
        DB::statement('ALTER TABLE automation_sends DROP CONSTRAINT automation_sends_kind_check');
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder', 'certificate'))");
    }
};
