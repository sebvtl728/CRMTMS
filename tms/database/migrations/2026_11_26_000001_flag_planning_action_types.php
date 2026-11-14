<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The CRM planning now reads the kind of an action type from its
 * meta.planning (« meeting », « call »), set by each organisme. Existing
 * organismes get the CAP FORMATION defaults on the items with those codes,
 * unless the key is already there (an organisme's choice, even « none »).
 * Run per tenant (FORCE RLS).
 */
return new class extends Migration
{
    private const PLANNING = ['prise-de-rendez-vous' => 'meeting', 'rendez-vous-physique' => 'meeting', 'appel' => 'call'];

    public function up(): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $items = DB::table('reference_items')->where('list', 'action_type')->whereIn('code', array_keys(self::PLANNING))->get(['id', 'code', 'meta']);
            foreach ($items as $item) {
                $meta = is_string($item->meta) ? (array) json_decode($item->meta, true) : [];
                if (array_key_exists('planning', $meta)) {
                    continue;
                }
                $meta['planning'] = self::PLANNING[$item->code];
                DB::table('reference_items')->where('id', $item->id)->update(['meta' => json_encode($meta)]);
            }
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        // The flags are harmless without the planning: nothing to undo.
    }
};
