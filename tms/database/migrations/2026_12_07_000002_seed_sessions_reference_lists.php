<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lot 2a: default values of the five new reference lists for the organismes
 * that already exist (new ones get them from the reference seeder). Run per
 * organisme (FORCE RLS). Idempotent: an item is matched on (list, code),
 * soft-deleted ones included; nothing existing is touched. Literal values
 * (as of 2a), never the seeder's constants: a later change of the defaults
 * must not rewrite history.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const VALUES = [
        'funding_type' => ['OPCO', 'Entreprise', 'CPF', 'France Travail', 'Région', 'Personnel'],
        'abandon_reason' => ['Démission', 'Maladie', 'Accident', 'Licenciement', 'Convenance personnelle', 'Autre'],
        'cancel_reason' => ['Effectif insuffisant', 'Demande du client', 'Report', 'Formateur indisponible', 'Financement refusé', 'Autre'],
        'bpf_category' => ["Salariés d'employeurs privés", "Demandeurs d'emploi", 'Particuliers', 'Autres'],
        'education_level' => ['Sans diplôme', 'Niveau 3 (CAP, BEP)', 'Niveau 4 (baccalauréat)', 'Niveau 5 (bac +2)', 'Niveau 6 (bac +3, bac +4)', 'Niveau 7 (bac +5)', 'Niveau 8 (doctorat)'],
    ];

    private const FORCE_MAJEURE = ['maladie', 'accident'];

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
                        'meta' => $list === 'abandon_reason' && in_array($code, self::FORCE_MAJEURE, true) ? json_encode(['force_majeure' => true]) : null,
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
