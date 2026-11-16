<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The attendance of a trainee (enrolment) at a séance (table attendances),
 * written by Attendances and the SeanceChanged listener only. Whole
 * minutes: present = the séance's duration, partial 0 < m < duration,
 * absent / excused 0; a cancelled séance brings them to 0 (kept). Journaled
 * with ids, status and minutes; the reason never in clear.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $seance_id
 * @property string $enrolment_id
 * @property string $status present|absent|partial|excused
 * @property int $minutes
 * @property string|null $reason ≤ 300, personal
 * @property bool $needs_review the séance changed its hours since
 * @property string|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Attendance extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance';

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const PARTIAL = 'partial';

    public const EXCUSED = 'excused';

    public const STATUSES = [self::PRESENT, self::PARTIAL, self::ABSENT, self::EXCUSED];

    /** @var list<string> */
    protected array $auditPersonal = ['reason'];

    /** @var list<string> */
    protected $fillable = ['seance_id', 'enrolment_id', 'status', 'minutes', 'reason', 'needs_review', 'recorded_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['needs_review' => false];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'needs_review' => 'boolean',
        ];
    }

    /** « présent », « partiel », « absent », « absence justifiée ». */
    public static function label(string $status): string
    {
        return match ($status) {
            self::PRESENT => 'présent',
            self::PARTIAL => 'partiel',
            self::ABSENT => 'absent',
            self::EXCUSED => 'absence justifiée',
            default => $status,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $s) => ['value' => $s, 'label' => ucfirst(self::label($s))], self::STATUSES);
    }
}
