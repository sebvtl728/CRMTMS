<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A module of a training (title, hours, modality, content), ordered by
 * position. Other modules read it through Trainings::modules().
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_id
 * @property int $position
 * @property string $title
 * @property string|null $duration_hours numeric(6,2) as a string
 * @property string|null $modality_id item of the référentiel training_modality
 * @property string|null $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TrainingModule extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** The journal records which module changed, never its (long) content. */
    /** @var list<string> */
    protected array $auditIgnore = ['content'];

    /** @var list<string> */
    protected $fillable = ['training_id', 'position', 'title', 'duration_hours', 'modality_id', 'content'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'duration_hours' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Training, $this>
     */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'modality_id')->withTrashed();
    }
}
