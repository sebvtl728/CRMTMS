<?php

namespace App\Modules\Sessions\Resources;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A closure period of the organisme (Paris days, bounds included): the
 * séance generator skips it, a séance planned there is flagged.
 *
 * @property string $id
 * @property string $tenant_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $label ≤ 120
 */
class ClosurePeriod extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'closure_period';

    protected $table = 'closure_periods';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['starts_on', 'ends_on', 'label'];

    protected static function booted(): void
    {
        static::creating(function (ClosurePeriod $period): void {
            $period->created_by ??= Auth::id();
            $period->updated_by ??= Auth::id();
        });
        static::updating(function (ClosurePeriod $period): void {
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

    public function label(): string
    {
        return $this->starts_on->equalTo($this->ends_on)
            ? "{$this->label} (le {$this->starts_on->format('d/m/Y')})"
            : "{$this->label} (du {$this->starts_on->format('d/m/Y')} au {$this->ends_on->format('d/m/Y')})";
    }
}
