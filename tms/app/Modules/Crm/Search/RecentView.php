<?php

namespace App\Modules\Crm\Search;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One fiche a user opened (see RecentViews). Bookkeeping of ids only, never
 * journaled.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $subject_type
 * @property string $subject_id
 * @property Carbon $viewed_at
 */
class RecentView extends Model
{
    use Auditable, BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected array $auditIgnore = ['id', 'tenant_id', 'user_id', 'subject_type', 'subject_id', 'viewed_at'];

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }
}
