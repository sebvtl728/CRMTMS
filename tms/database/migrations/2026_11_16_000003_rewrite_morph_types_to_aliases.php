<?php

use App\Modules\Core\Audit\MorphDataMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The morph map is now enforced: class names stored in type columns become
 * aliases (role assignments would otherwise be lost). The audit journal is
 * append-only (trigger): its immutability trigger is lifted for this one
 * statement set, inside the migration transaction, and only the type column
 * is rewritten (what was recorded is unchanged, only the name of the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table audit_logs disable trigger audit_logs_immutable');
        MorphDataMigration::run(DB::connection());
        DB::statement('alter table audit_logs enable trigger audit_logs_immutable');
    }

    public function down(): void
    {
        // Aliases stay valid: nothing to restore.
    }
};
