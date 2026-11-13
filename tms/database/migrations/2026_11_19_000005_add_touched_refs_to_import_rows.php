<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fiches an import row updated ([{type, id, at}]): with the « at » of
        // created_refs, the last state the import left each fiche in. An
        // undo keeps a fiche whose updated_at differs (someone changed it).
        Schema::table('import_rows', function (Blueprint $table) {
            $table->jsonb('touched_refs')->default('[]');
        });
        DB::statement('create index import_rows_touched_refs_gin on import_rows using gin (touched_refs jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropColumn('touched_refs');
        });
    }
};
