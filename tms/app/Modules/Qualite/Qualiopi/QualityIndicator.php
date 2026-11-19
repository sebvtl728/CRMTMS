<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The organisme's work on one Qualiopi indicator of a referential version:
 * applicability (with its justification when not applicable), status,
 * responsible, review date, notes. No row: applicable, to do.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $referential_version
 * @property int $number
 * @property bool $applicable
 * @property string|null $not_applicable_reason
 * @property string $status todo | in_progress | ready | to_review
 * @property string|null $responsible_user_id
 * @property CarbonImmutable|null $review_on
 * @property string|null $notes
 * @property string|null $updated_by
 */
class QualityIndicator extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'quality_indicator';

    public const STATUSES = ['todo' => 'À faire', 'in_progress' => 'En cours', 'ready' => 'Prêt', 'to_review' => 'À revoir'];

    /** @var list<string> */
    protected array $auditIgnore = ['updated_by'];

    /** @var list<string> */
    protected $fillable = ['referential_version', 'number', 'applicable', 'not_applicable_reason', 'status', 'responsible_user_id', 'review_on', 'notes'];

    protected $attributes = ['applicable' => true, 'status' => 'todo'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'applicable' => 'boolean', 'review_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::saving(function (QualityIndicator $indicator): void {
            $indicator->updated_by = Auth::id() ?? $indicator->updated_by;
        });
    }
}
