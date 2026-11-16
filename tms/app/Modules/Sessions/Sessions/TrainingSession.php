<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A session of a training of the catalogue (table training_sessions),
 * written by SessionWriter (number, DAS and hours from the training).
 * training_id, organisation_id: ids of the Catalogue and the Crm, read
 * through their public services only (Trainings, Crm\Public\Fiches).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number S-<AAAA>-<NNNN>, unique in the organisme
 * @property string $training_id
 * @property string $das_id the training's DAS (« Ses DAS » visibility)
 * @property string $kind intra|inter (SessionKind)
 * @property string|null $organisation_id the entreprise of an intra session
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string|null $planned_hours numeric(6,2) as a string
 * @property string $location_kind address|client|remote (SessionLocation)
 * @property string|null $location_text
 * @property int|null $capacity places of an inter session
 * @property string|null $responsible_user_id
 * @property SessionStatus $status stored state (see effectiveStatus())
 * @property string|null $cancel_reason_id
 * @property CarbonImmutable|null $cancelled_on
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class TrainingSession extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'training_session';

    protected $table = 'training_sessions';

    /**
     * Free text that may describe a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['notes'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = [
        'training_id', 'das_id', 'kind', 'organisation_id', 'starts_on', 'ends_on', 'planned_hours',
        'location_kind', 'location_text', 'capacity', 'responsible_user_id', 'status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'planned',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrainingSession $session): void {
            $session->created_by ??= Auth::id();
            $session->updated_by ??= Auth::id();
        });
        static::updating(function (TrainingSession $session): void {
            if (Auth::id() !== null) {
                $session->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
            'planned_hours' => 'decimal:2',
            'capacity' => 'integer',
        ];
    }

    /**
     * The state on $today (a Paris day): « En cours » / « Terminée » from the
     * dates while planned or confirmed.
     */
    public function effectiveStatus(CarbonImmutable $today): SessionStatus
    {
        return $this->status->effective($this->starts_on, $this->ends_on, $today);
    }

    public function isCancelled(): bool
    {
        return $this->status === SessionStatus::Cancelled;
    }

    /**
     * Today in Paris, as a date (the sessions' days are Paris days).
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Paris')->startOfDay();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
