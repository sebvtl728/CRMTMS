<?php

namespace App\Modules\Sessions\Trainers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A person of the CRM declared trainer (table trainers), written by
 * Trainers only. The person is reached through Crm\Public (Fiches,
 * Trainees), never its table; the linked user account (optional, one
 * trainer per account) sees his own séances (scope « Ses séances »).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $status employee|freelance (TrainerStatus)
 * @property bool $active
 * @property string|null $user_id
 * @property Carbon|null $deleted_at
 */
class Trainer extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'trainer';

    protected $table = 'trainers';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['person_id', 'status', 'active', 'user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        static::creating(function (Trainer $trainer): void {
            $trainer->created_by ??= Auth::id();
            $trainer->updated_by ??= Auth::id();
        });
        static::updating(function (Trainer $trainer): void {
            if (Auth::id() !== null) {
                $trainer->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function statusLabel(): string
    {
        return TrainerStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    /**
     * @return HasMany<TrainerDas, $this>
     */
    public function das(): HasMany
    {
        return $this->hasMany(TrainerDas::class);
    }

    /**
     * @return HasMany<TrainerUnavailability, $this>
     */
    public function unavailabilities(): HasMany
    {
        return $this->hasMany(TrainerUnavailability::class);
    }
}
