<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The minutes realised by a trainee (enrolment) on a module dated as an
 * interval for a Paris month (table monthly_hours, month = the 1st), written
 * by MonthlyHours only. Journaled (ids and minutes; no personal value).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $session_module_id
 * @property CarbonImmutable $month the 1st of the month
 * @property int $minutes
 * @property string|null $trainer_id
 * @property string|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MonthlyHour extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'monthly_hour';

    protected $table = 'monthly_hours';

    /** @var list<string> */
    protected $fillable = ['enrolment_id', 'session_module_id', 'month', 'minutes', 'trainer_id', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'minutes' => 'integer',
        ];
    }

    /** « 2026-09 » */
    public function monthKey(): string
    {
        return $this->month->format('Y-m');
    }
}
