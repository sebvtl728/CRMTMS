<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A dated séance of a session module (table seances), written by Seances
 * and SeanceGenerator only. The day is a Paris day and the hours are wall
 * clock (time without zone): 9 h–17 h stays 9 h–17 h across a change of
 * hour. duration_minutes = end − start − break, computed by the database
 * (whole minutes, never a float).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property string $session_module_id
 * @property CarbonImmutable $date
 * @property string $starts_at « H:i:s »
 * @property string $ends_at « H:i:s »
 * @property int $break_minutes
 * @property int $duration_minutes generated
 * @property string|null $trainer_id
 * @property string|null $room_id
 * @property string|null $place_text ≤ 300 (outside the centre, remote)
 * @property string $status planned|cancelled
 * @property string|null $cancel_reason ≤ 300
 * @property string|null $note ≤ 1000, internal
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Seance extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'seance';

    public const PLANNED = 'planned';

    public const CANCELLED = 'cancelled';

    protected $table = 'seances';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected array $auditPersonal = ['note', 'cancel_reason'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'session_module_id', 'date', 'starts_at', 'ends_at', 'break_minutes',
        'trainer_id', 'room_id', 'place_text', 'status', 'cancel_reason', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PLANNED, 'break_minutes' => 0];

    protected static function booted(): void
    {
        static::creating(function (Seance $seance): void {
            $seance->created_by ??= Auth::id();
            $seance->updated_by ??= Auth::id();
        });
        static::updating(function (Seance $seance): void {
            if (Auth::id() !== null) {
                $seance->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'break_minutes' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /** « 09:00 » */
    public function start(): string
    {
        return substr($this->starts_at, 0, 5);
    }

    /** « 17:00 » */
    public function end(): string
    {
        return substr($this->ends_at, 0, 5);
    }

    /** The instant it starts, Paris time (the change of hour respected). */
    public function startsAtInstant(): CarbonImmutable
    {
        return self::instant($this->date->toDateString(), $this->start());
    }

    public function endsAtInstant(): CarbonImmutable
    {
        return self::instant($this->date->toDateString(), $this->end());
    }

    public static function instant(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} ".substr($time, 0, 5), 'Europe/Paris')
            ?: throw new \InvalidArgumentException("Horaire invalide : {$date} {$time}");
    }

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /**
     * @return BelongsTo<SessionModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(SessionModule::class, 'session_module_id');
    }
}
