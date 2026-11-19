<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The pathway of a candidature: alternance or trainee (continuing
 * training), null while undecided. A candidature that already has an
 * alternance contract is alternance (set per organisme: FORCE RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->string('pathway', 20)->nullable()->after('status');
        });
        DB::statement("ALTER TABLE candidatures ADD CONSTRAINT candidatures_pathway_check CHECK (pathway IN ('alternance', 'trainee'))");
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::statement("UPDATE candidatures SET pathway = 'alternance' WHERE id IN (SELECT candidature_id FROM alternance_contracts)");
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->dropColumn('pathway');
        });
    }
};
