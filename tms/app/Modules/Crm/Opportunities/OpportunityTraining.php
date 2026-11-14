<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A training of the catalogue on an opportunity (written by
 * OpportunityTrainings). The training is read through Catalogue\Trainings
 * only: its id is all this table holds.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $opportunity_id
 * @property string $training_id
 * @property string $quantity numeric(8,2) as a string (« 3.00 »)
 * @property int $position order of the lines, from 0
 */
class OpportunityTraining extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'opportunity_trainings';

    /** @var list<string> */
    protected $fillable = ['opportunity_id', 'training_id', 'quantity', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class)->withTrashed();
    }
}
