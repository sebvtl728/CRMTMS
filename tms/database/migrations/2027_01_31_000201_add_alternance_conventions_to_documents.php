<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The conventions of an alternance dossier (apprenticeship,
 * professionalisation) are documents too (spec 2026-11-25).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('convention', 'contract', 'convocation', 'completion_certificate', 'realisation_certificate', 'apprenticeship_convention', 'professionalisation_convention'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('convention', 'contract', 'convocation', 'completion_certificate', 'realisation_certificate'))");
    }
};
