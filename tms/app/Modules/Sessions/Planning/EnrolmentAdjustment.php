<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Sessions\Enrolments\Enrolment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A gap of one enrolment from the séances of its session (its individual
 * planning, table enrolment_adjustments), written by IndividualPlanningWriter
 * and read through IndividualPlanning only:
 *  - exempt_seance: one séance of his session left out of his planning;
 *  - exempt_module: a whole module of his session (later séances included);
 *  - own_hours: his own hours at a séance of his session (within it);
 *  - added_seance: a séance of another session added (catch-up), with own
 *    hours or not.
 * duration_minutes is computed by the database (null without own hours).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $kind
 * @property string|null $seance_id
 * @property string|null $session_module_id
 * @property string|null $starts_at « H:i:s »
 * @property string|null $ends_at « H:i:s »
 * @property int|null $break_minutes
 * @property int|null $duration_minutes generated
 * @property string|null $reason ≤ 300, personal
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class EnrolmentAdjustment extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'enrolment_adjustment';

    public const EXEMPT_SEANCE = 'exempt_seance';

    public const EXEMPT_MODULE = 'exempt_module';

    public const ADDED_SEANCE = 'added_seance';

    public const OWN_HOURS = 'own_hours';

    protected $table = 'enrolment_adjustments';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by'];

    /** @var list<string> */
    protected array $auditPersonal = ['reason'];

    /** @var list<string> */
    protected $fillable = ['enrolment_id', 'kind', 'seance_id', 'session_module_id', 'starts_at', 'ends_at', 'break_minutes', 'reason'];

    protected static function booted(): void
    {
        static::creating(function (EnrolmentAdjustment $adjustment): void {
            $adjustment->created_by ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function hasOwnHours(): bool
    {
        return $this->starts_at !== null;
    }

    /**
     * @return BelongsTo<Enrolment, $this>
     */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    /**
     * @return BelongsTo<Seance, $this>
     */
    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class)->withTrashed();
    }
}
