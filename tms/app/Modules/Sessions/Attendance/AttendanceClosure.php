<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Paris month of a session whose attendance was closed (table
 * attendance_closures, one row per session and month), written by Closures
 * only: closed while closed_at is set; a reopening clears it and keeps who,
 * when and why (the last reason, never journaled in clear).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property CarbonImmutable $month the 1st of the month
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $reopened_at
 * @property string|null $reopened_by
 * @property string|null $reopen_reason ≤ 300, personal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceClosure extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance_closure';

    /** @var list<string> */
    protected array $auditPersonal = ['reopen_reason'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'month', 'closed_at', 'closed_by', 'reopened_at', 'reopened_by', 'reopen_reason'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
