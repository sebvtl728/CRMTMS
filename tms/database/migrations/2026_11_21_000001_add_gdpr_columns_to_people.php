<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GDPR on people: legal basis of the processing (default: legitimate
     * interest for an interlocuteur, consent for a particulier), opposition
     * to prospecting, and « Conserver » (kept out of the retention list until
     * a date, with a reason).
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('legal_basis', 30)->default('interet_legitime');
            $table->timestampTz('prospecting_opt_out_at')->nullable();
            $table->timestampTz('gdpr_kept_until')->nullable();
            $table->string('gdpr_kept_reason', 500)->nullable();
        });

        DB::statement("alter table people add constraint people_legal_basis_check check (legal_basis in ('interet_legitime','consentement','contrat','obligation_legale'))");

        // Existing particuliers: consent (FORCE RLS: per organisme).
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::table('people')->where('is_individual', true)->update(['legal_basis' => 'consentement']);
            DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
        }
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['legal_basis', 'prospecting_opt_out_at', 'gdpr_kept_until', 'gdpr_kept_reason']);
        });
    }
};
