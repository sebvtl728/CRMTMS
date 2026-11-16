<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot 2a: the funding type a dossier created from a quote is funded by is
 * now a flag of the item (meta.default_for organisation|person), no longer
 * its code. For the organismes that already exist, the items « entreprise »
 * and « personnel » (live, by code) get the flag, unless an item is already
 * flagged for that kind. Run per organisme (FORCE RLS), once by the migrator:
 * a flag the organisme removes afterwards is not restored.
 */
return new class extends Migration
{
    /** @var array<string, string> code => kind (literal values, as of 2a) */
    private const DEFAULTS = ['entreprise' => 'organisation', 'personnel' => 'person'];

    public function up(): void
    {
        $this->eachTenant(function (): void {
            foreach (self::DEFAULTS as $code => $kind) {
                // A kind the organisme already flagged an item for is left as it is.
                if (DB::table('reference_items')->where('list', 'funding_type')->whereNull('deleted_at')->where('meta->default_for', $kind)->exists()) {
                    continue;
                }
                $item = DB::table('reference_items')->where('list', 'funding_type')->where('code', $code)->whereNull('deleted_at')->first(['id', 'meta']);
                if ($item === null) {
                    continue;
                }
                $meta = is_string($item->meta) ? (array) json_decode($item->meta, true) : [];
                DB::table('reference_items')->where('id', $item->id)->update(['meta' => json_encode([...$meta, 'default_for' => $kind])]);
            }
        });
    }

    public function down(): void
    {
        $this->eachTenant(function (): void {
            foreach (DB::table('reference_items')->where('list', 'funding_type')->whereNotNull('meta')->get(['id', 'meta']) as $item) {
                $meta = (array) json_decode((string) $item->meta, true);
                unset($meta['default_for']);
                DB::table('reference_items')->where('id', $item->id)->update(['meta' => $meta === [] ? null : json_encode($meta)]);
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
