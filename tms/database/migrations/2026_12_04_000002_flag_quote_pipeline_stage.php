<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sending a quote moves its opportunity to the stage flagged
 * meta.quote_stage, chosen by each organisme. Existing organismes get the
 * CAP FORMATION default (« Proposition », by its code) unless one of their
 * stages already carries the key (an organisme's choice, even false).
 * Run per tenant (FORCE RLS).
 */
return new class extends Migration
{
    private const CODE = 'proposition';

    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $stages = DB::table('reference_items')->where('list', 'pipeline_stage')->whereNull('deleted_at')->get(['id', 'code', 'meta']);
            $meta = fn (object $item): array => is_string($item->meta) ? (array) json_decode($item->meta, true) : [];

            if ($stages->contains(fn (object $item) => array_key_exists('quote_stage', $meta($item)))) {
                continue;
            }
            $proposition = $stages->firstWhere('code', self::CODE);
            if ($proposition === null || ($meta($proposition)['outcome'] ?? null) !== null) {
                continue;
            }
            DB::table('reference_items')->where('id', $proposition->id)
                ->update(['meta' => json_encode([...$meta($proposition), 'quote_stage' => true])]);
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        // The flag is harmless without the sending: nothing to undo.
    }
};
