<?php

namespace App\Modules\Qualite\Registers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An improvement action (indicator 32), written by Registers only: planned,
 * in progress, done (requires its date and result, CHECK) or abandoned;
 * optionally born from a complaint.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string|null $description
 * @property string $source_id
 * @property string|null $complaint_id
 * @property string|null $responsible_user_id
 * @property CarbonImmutable|null $due_on
 * @property string $status planned|in_progress|done|abandoned
 * @property CarbonImmutable|null $done_on
 * @property string|null $result
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImprovementAction extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'quality_action';

    public const STATUSES = ['planned' => 'Prévue', 'in_progress' => 'En cours', 'done' => 'Réalisée', 'abandoned' => 'Abandonnée'];

    protected $table = 'quality_actions';

    /** @var list<string> */
    protected $fillable = ['title', 'description', 'source_id', 'complaint_id', 'responsible_user_id', 'due_on', 'status', 'done_on', 'result', 'created_by'];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'done_on' => 'immutable_date'];
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'complaint_id');
    }
}
