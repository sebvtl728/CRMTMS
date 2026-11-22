<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Types of an organisation (entreprise cliente, OPCO, financeur public,
 * sous-traitant…): a reference list of the organisme (organisation_type,
 * meta.funder for the funders), several per fiche (organisation_types).
 * Default values for the organismes that already exist, run per organisme
 * (FORCE RLS), literal values (as of 0.15), nothing existing touched.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: bool}> label, funder */
    private const VALUES = [
        ['Entreprise cliente', false], ['OPCO', true], ['Financeur public', true],
        ['Sous-traitant', false], ['Co-traitant', false], ['Prescripteur', false],
    ];

    public function up(): void
    {
        Schema::create('organisation_types', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('reference_items');
            $table->primary(['organisation_id', 'type_id']);
            $table->index('type_id');
        });
        Rls::enable('organisation_types');

        $this->eachTenant(function (string $tenantId): void {
            foreach (self::VALUES as $i => [$label, $funder]) {
                $code = Str::slug($label);
                if (DB::table('reference_items')->where('list', 'organisation_type')->where('code', $code)->exists()) {
                    continue;
                }
                DB::table('reference_items')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'list' => 'organisation_type', 'code' => $code,
                    'label' => $label, 'position' => $i + 1, 'is_system' => false, 'is_active' => true,
                    'meta' => $funder ? json_encode(['funder' => true]) : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_types');
        $this->eachTenant(fn () => DB::table('reference_items')->where('list', 'organisation_type')->delete());
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
