<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 0.8.0: every session that exists (trashed ones included: they can come
 * back) gets one « interval » module: the title of its training, its planned
 * hours in whole minutes (rounded half up, null without hours), its dates and
 * modality. No data invented: the catalogue's split into modules only
 * applies to the sessions created afterwards. Per organisme (FORCE RLS),
 * idempotent (a session that has a module is skipped). Literal names (as of
 * 0.8.0): a migration does not follow the module code that changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfill();
    }

    public function backfill(): void
    {
        $this->eachTenant(function (string $tenantId): void {
            DB::table('training_sessions as s')
                ->join('trainings as t', 't.id', '=', 's.training_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('session_modules as m')->whereColumn('m.training_session_id', 's.id'))
                ->select(['s.id', 's.planned_hours', 's.modality_id', 's.starts_on', 's.ends_on', 't.title'])
                // By id: the sessions done drop out of the query as it goes.
                ->chunkById(500, function ($sessions) use ($tenantId): void {
                    $now = now();
                    DB::table('session_modules')->insert($sessions->map(fn ($s) => [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'training_session_id' => $s->id,
                        'position' => 1,
                        'title' => mb_substr((string) $s->title, 0, 200),
                        'planned_minutes' => self::minutes($s->planned_hours),
                        'modality_id' => $s->modality_id,
                        'dating_mode' => 'interval',
                        'starts_on' => $s->starts_on,
                        'ends_on' => $s->ends_on,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                }, 's.id', 'id');
        });
    }

    /** « 10.33 » h → 620 min; null or 0 → null. */
    private static function minutes(?string $hours): ?int
    {
        if ($hours === null) {
            return null;
        }
        $minutes = BigDecimal::of($hours)->multipliedBy(60)->toScale(0, RoundingMode::HalfUp)->toInt();

        return $minutes > 0 ? $minutes : null;
    }

    public function down(): void
    {
        // The modules table goes with the previous migration.
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
