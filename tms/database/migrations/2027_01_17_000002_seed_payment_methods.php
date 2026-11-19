<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finance (0.9.1): default payment methods for the organismes
 * that already exist (new ones get them from the reference seeder). Run per
 * organisme (FORCE RLS). Idempotent: an item is matched on (list, code),
 * soft-deleted ones included; nothing existing is touched. Literal values
 * (as of 0.9.1), never the seeder's constants: a later change of the defaults
 * must not rewrite history.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const VALUES = [
        'payment_method' => ['Virement', 'Chèque', 'Carte bancaire', 'Espèces', 'Prélèvement'],
    ];

    public function up(): void
    {
        $this->eachTenant(function (string $tenantId): void {
            foreach (self::VALUES as $list => $labels) {
                foreach ($labels as $i => $label) {
                    $code = Str::slug($label);
                    $exists = DB::table('reference_items')->where('list', $list)->where('code', $code)->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('reference_items')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'list' => $list,
                        'code' => $code,
                        'label' => $label,
                        'position' => $i + 1,
                        'is_system' => false,
                        'is_active' => true,
                        'meta' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $this->eachTenant(function (): void {
            DB::table('reference_items')->whereIn('list', array_keys(self::VALUES))->delete();
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
