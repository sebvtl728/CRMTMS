<?php

namespace App\Modules\Sessions\Trainers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A period a trainer is not available (instants, end after start), entered
 * by a manager or the trainer himself. The reason is personal data: never
 * journaled in clear, cleared by the anonymisation of the person.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $trainer_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason ≤ 200
 */
class TrainerUnavailability extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'trainer_unavailability';

    protected $table = 'trainer_unavailabilities';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by'];

    /** @var list<string> */
    protected array $auditPersonal = ['reason'];

    /** @var list<string> */
    protected $fillable = ['trainer_id', 'starts_at', 'ends_at', 'reason'];

    protected static function booted(): void
    {
        static::creating(function (TrainerUnavailability $u): void {
            $u->created_by ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * « Du 29/03/2027 09:00 au 29/03/2027 17:30 », Paris time.
     */
    public function label(): string
    {
        $paris = fn (CarbonImmutable $at) => $at->setTimezone('Europe/Paris')->format('d/m/Y H:i');

        return 'Du '.$paris($this->starts_at).' au '.$paris($this->ends_at);
    }

    /**
     * @return BelongsTo<Trainer, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
