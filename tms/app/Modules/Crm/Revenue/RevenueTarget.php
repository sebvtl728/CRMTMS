<?php

namespace App\Modules\Crm\Revenue;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The revenue target of a DAS for a period of exactly this type (written by
 * RevenueTargets).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $das_id
 * @property string $period_type month|quarter|year
 * @property Carbon $period_start first day of the period
 * @property string $amount numeric(12,2) as a string
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class RevenueTarget extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'revenue_targets';

    /** @var list<string> */
    protected $fillable = ['das_id', 'period_type', 'period_start', 'amount'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (RevenueTarget $target): void {
            $target->created_by ??= Auth::id();
        });
        static::saving(function (RevenueTarget $target): void {
            $target->updated_by = Auth::id() ?? $target->updated_by;
        });
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function das(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'das_id')->withTrashed();
    }
}
