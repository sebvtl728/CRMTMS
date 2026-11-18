<?php

namespace App\Modules\Finance\Plans;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How a dossier is invoiced (spec 0.9 § 4.2): one row per dossier, created
 * at its first setting; written by BillingPlans only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dossier_id
 * @property string $rhythm end|monthly|schedule
 * @property string $basis realised|planned
 * @property string|null $unjustified_absences not_billed|bill_contractor; null: the organisme's default
 * @property list<array{due_on: string, percent: int}>|null $schedule rhythm « schedule » only
 * @property CarbonImmutable|null $locked_at the first invoice of the dossier was validated
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 */
class BillingPlan extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'billing_plan';

    /** @var list<string> */
    protected $fillable = ['dossier_id', 'rhythm', 'basis', 'unjustified_absences', 'schedule', 'locked_at', 'updated_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'rhythm' => BillingPlans::END,
        'basis' => BillingPlans::REALISED,
    ];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'locked_at' => 'immutable_datetime',
        ];
    }
}
