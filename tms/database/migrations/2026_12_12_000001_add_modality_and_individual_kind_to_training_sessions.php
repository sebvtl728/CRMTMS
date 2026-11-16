<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lot 2a, task 6b: individual sessions and training modality.
 *
 * - kind accepts « individual » (one trainee, no places, entreprise
 *   optional); the intra/inter rules are unchanged.
 * - the « Modalités de formation » list (training_modality) is created for
 *   the organismes that already exist (new ones get it from the reference
 *   seeder), then every session gets modality_id, existing ones « Présentiel ».
 *   Per organisme (FORCE RLS), idempotent: an item is matched on (list, code),
 *   soft-deleted ones included. Literal values (as of 2a).
 */
return new class extends Migration
{
    private const LABELS = ['Présentiel', 'Distanciel (classe virtuelle)', 'FOAD / e-learning', 'Mixte'];

    public function up(): void
    {
        if (! Schema::hasColumn('training_sessions', 'modality_id')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('reference_items');
                $table->index('modality_id');
            });
        }

        $this->seedModalities(backfill: true);

        DB::statement('alter table training_sessions alter column modality_id set not null');

        DB::statement('alter table training_sessions drop constraint training_sessions_kind_check');
        DB::statement("alter table training_sessions add constraint training_sessions_kind_check check (kind in ('intra', 'inter', 'individual'))");
        DB::statement('alter table training_sessions drop constraint training_sessions_intra_check');
        // Intra: one entreprise, no places; inter: places, no entreprise;
        // individual: no places, entreprise optional.
        DB::statement("alter table training_sessions add constraint training_sessions_intra_check check (
            (kind = 'intra' and organisation_id is not null and capacity is null)
            or (kind = 'inter' and organisation_id is null and capacity is not null and capacity >= 1)
            or (kind = 'individual' and capacity is null))");
    }

    /**
     * Creates the missing modalities of every organisme (idempotent); with
     * $backfill, gives the sessions without one « Présentiel ».
     */
    public function seedModalities(bool $backfill = false): void
    {
        $this->eachTenant(function (string $tenantId) use ($backfill): void {
            foreach (self::LABELS as $i => $label) {
                $code = Str::slug($label);
                if (DB::table('reference_items')->where('list', 'training_modality')->where('code', $code)->exists()) {
                    continue;
                }
                DB::table('reference_items')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'list' => 'training_modality',
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
            if (! $backfill) {
                return;
            }
            $default = DB::table('reference_items')->where('list', 'training_modality')->where('code', 'presentiel')->value('id');
            DB::table('training_sessions')->whereNull('modality_id')->update(['modality_id' => $default]);
        });
    }

    public function down(): void
    {
        DB::statement('alter table training_sessions drop constraint training_sessions_kind_check');
        DB::statement("alter table training_sessions add constraint training_sessions_kind_check check (kind in ('intra', 'inter'))");
        DB::statement('alter table training_sessions drop constraint training_sessions_intra_check');
        DB::statement("alter table training_sessions add constraint training_sessions_intra_check check (
            (kind = 'intra' and organisation_id is not null and capacity is null)
            or (kind = 'inter' and organisation_id is null and capacity is not null and capacity >= 1))");
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modality_id');
        });
        $this->eachTenant(fn () => DB::table('reference_items')->where('list', 'training_modality')->delete());
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
