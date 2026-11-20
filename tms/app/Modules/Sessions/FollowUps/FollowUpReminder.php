<?php

namespace App\Modules\Sessions\FollowUps;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A due follow-up processed once (lot 1e): the trainer's call after an
 * enrolment (kind trainer, subject = enrolment) or the reactivation call
 * after a dossier (kind reactivation, subject = dossier), at a number of
 * months; created (activity_id) or skipped (reason).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind trainer|reactivation
 * @property string $subject_id
 * @property int $months
 * @property string|null $activity_id
 * @property string $outcome created|skipped
 * @property string|null $reason
 * @property CarbonImmutable $processed_at
 */
class FollowUpReminder extends Model
{
    use BelongsToTenant, HasUuids;

    public const TRAINER = 'trainer';

    public const REACTIVATION = 'reactivation';

    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    /** @var list<string> */
    protected $fillable = ['kind', 'subject_id', 'months', 'activity_id', 'outcome', 'reason', 'processed_at'];

    protected function casts(): array
    {
        return ['months' => 'integer', 'processed_at' => 'immutable_datetime'];
    }

    /**
     * Records a processed follow-up; false when it already was (unique key).
     */
    public static function record(string $kind, string $subjectId, int $months, ?string $activityId, ?string $reason = null): void
    {
        self::query()->create([
            'kind' => $kind, 'subject_id' => $subjectId, 'months' => $months, 'activity_id' => $activityId,
            'outcome' => $activityId === null ? self::SKIPPED : self::CREATED, 'reason' => $reason, 'processed_at' => CarbonImmutable::now(),
        ]);
    }
}
