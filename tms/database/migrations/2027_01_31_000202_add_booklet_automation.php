<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The automation of the apprenticeship booklet: monthly link and reminders (spec 2026-11-24, B3). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE automation_sends DROP CONSTRAINT automation_sends_kind_check');
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder', 'certificate', 'convention', 'booklet', 'booklet_reminder'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE automation_sends DROP CONSTRAINT automation_sends_kind_check');
        DB::statement("ALTER TABLE automation_sends ADD CONSTRAINT automation_sends_kind_check CHECK (kind IN ('convocation', 'day_before', 'signature_reminder', 'certificate', 'convention'))");
    }
};
