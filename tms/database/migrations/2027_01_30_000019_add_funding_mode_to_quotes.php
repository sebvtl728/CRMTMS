<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The planned funding of a quote (« Financement envisagé », a value of the
 * « Modes de financement » list), copied from the opportunity at creation
 * and printed on the quote. A funding mode links to the funding type of the
 * dossier it gives (meta.funding_type_id): the dossier created from the
 * quote starts with that funding. For the organismes that already exist,
 * each live mode is linked once to the live funding type of the same code
 * (OPCO → OPCO, CPF → CPF…), unless the organisme already chose; the drafts
 * take the mode of their opportunity. Per organisme (FORCE RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignUuid('funding_mode_id')->nullable()->after('contact_person_id')->constrained('reference_items');
        });

        $this->linkFundingModes();
        $this->eachTenant(function (): void {
            DB::update(
                "update quotes q set funding_mode_id = o.funding_mode_id from opportunities o
                 where o.id = q.opportunity_id and q.status = 'draft' and q.funding_mode_id is null and o.funding_mode_id is not null"
            );
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funding_mode_id');
        });
    }

    /** Links each live funding mode without link to the live funding type of the same code. Idempotent. */
    public function linkFundingModes(): void
    {
        $this->eachTenant(function (): void {
            $types = DB::table('reference_items')->where('list', 'funding_type')->whereNull('deleted_at')->whereNotNull('code')->pluck('id', 'code');
            foreach (DB::table('reference_items')->where('list', 'funding_mode')->whereNull('deleted_at')->get(['id', 'code', 'meta']) as $mode) {
                $meta = is_string($mode->meta) ? (array) json_decode($mode->meta, true) : [];
                if (array_key_exists('funding_type_id', $meta) || ! isset($types[$mode->code])) {
                    continue;
                }
                DB::table('reference_items')->where('id', $mode->id)->update(['meta' => json_encode([...$meta, 'funding_type_id' => $types[$mode->code]])]);
            }
        });
    }

    private function eachTenant(callable $fn): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $fn((string) $tenantId);
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }
};
