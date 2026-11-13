<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the stage history of an opportunity (append-only, written by
 * OpportunityStage). from_stage_id is null for the creation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $opportunity_id
 * @property string|null $from_stage_id
 * @property string $to_stage_id
 * @property string|null $changed_by
 * @property Carbon $changed_at
 */
class StageChange extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'opportunity_stage_changes';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['opportunity_id', 'from_stage_id', 'to_stage_id', 'changed_by', 'changed_at'];

    /** Microseconds kept: two moves in the same second stay ordered. */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'from_stage_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'to_stage_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
