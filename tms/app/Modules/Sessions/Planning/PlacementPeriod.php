<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * A placement period (stage en entreprise) of a session: common dates, and
 * per trainee a host entreprise and own dates (PlacementPeriodHost).
 * Outside the modules: never counted in hours; the generator creates no
 * séance on its common dates. Written by PlacementPeriods only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $label ≤ 120
 */
class PlacementPeriod extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'placement_period';

    protected $table = 'placement_periods';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'starts_on', 'ends_on', 'label'];

    protected static function booted(): void
    {
        static::creating(function (PlacementPeriod $period): void {
            $period->created_by ??= Auth::id();
            $period->updated_by ??= Auth::id();
        });
        static::updating(function (PlacementPeriod $period): void {
            if (Auth::id() !== null) {
                $period->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<PlacementPeriodHost, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(PlacementPeriodHost::class);
    }
}
