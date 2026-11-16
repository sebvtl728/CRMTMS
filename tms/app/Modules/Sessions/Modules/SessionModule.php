<?php

namespace App\Modules\Sessions\Modules;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A module of a training session (table session_modules), copied from the
 * training's modules (Catalogue\Trainings::modules) at creation, then
 * written by SessionModules only. Hours in whole minutes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property int $position 1, 2… in the session
 * @property string $title ≤ 200
 * @property int|null $planned_minutes > 0 (null: the catalogue gave no hours)
 * @property string|null $modality_id « Modalités de formation » (reference item)
 * @property string $dating_mode interval|seances (DatingMode)
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class SessionModule extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'session_module';

    protected $table = 'session_modules';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'position', 'title', 'planned_minutes', 'modality_id', 'dating_mode', 'starts_on', 'ends_on'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'dating_mode' => 'interval',
    ];

    protected static function booted(): void
    {
        static::creating(function (SessionModule $module): void {
            $module->created_by ??= Auth::id();
            $module->updated_by ??= Auth::id();
        });
        static::updating(function (SessionModule $module): void {
            if (Auth::id() !== null) {
                $module->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'planned_minutes' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * Séances, attendances or monthly hours hang on the module: it is then
     * neither deleted nor switched to the other dating mode. Nothing yet:
     * the séances (task 3) and the realised hours (task 4) add their tables.
     */
    public function hasActivity(): bool
    {
        return false;
    }

    /**
     * An interval module whose dates leave those of its session (the session
     * moved afterwards): flagged, never moved.
     */
    public function outOfRange(TrainingSession $session): bool
    {
        return $this->dating_mode === DatingMode::Interval->value
            && (($this->starts_on !== null && $this->starts_on->lt($session->starts_on))
                || ($this->ends_on !== null && $this->ends_on->gt($session->ends_on)));
    }

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }
}
