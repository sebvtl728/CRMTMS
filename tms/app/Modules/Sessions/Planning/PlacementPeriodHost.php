<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The placement of one trainee (enrolment) during a placement period: the
 * host entreprise of the CRM (Crm\Public\Fiches, optional) and own dates
 * replacing the common ones (both or none).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $placement_period_id
 * @property string $enrolment_id
 * @property string|null $organisation_id
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 */
class PlacementPeriodHost extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'placement_period_host';

    protected $table = 'placement_period_hosts';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['placement_period_id', 'enrolment_id', 'organisation_id', 'starts_on', 'ends_on'];

    protected static function booted(): void
    {
        static::creating(function (PlacementPeriodHost $host): void {
            $host->created_by ??= Auth::id();
            $host->updated_by ??= Auth::id();
        });
        static::updating(function (PlacementPeriodHost $host): void {
            if (Auth::id() !== null) {
                $host->updated_by = Auth::id();
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
}
