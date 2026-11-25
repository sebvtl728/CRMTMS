<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company visit of the apprentice's trainer: date and points discussed
 * (the signatures of the visit stay by hand on the PDF).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property CarbonImmutable $visited_on
 * @property string|null $points
 * @property string|null $trainer_user_id
 */
class BookletVisit extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'booklet_visit';

    public const MAX_POINTS = 3000;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @var list<string> */
    protected array $auditPersonal = ['points'];

    protected function casts(): array
    {
        return ['visited_on' => 'immutable_date'];
    }
}
