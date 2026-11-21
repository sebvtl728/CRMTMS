<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The signature of a trainee on a half-day (table attendance_signatures),
 * one per slot and enrolment, written by AttendanceSigning only. The drawing
 * (PNG under the attendance_signatures area), the address and the browser
 * are personal: never journaled, erased by the anonymisation (signed_at
 * kept). source = online (the shared link) or the key of a connector.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $slot_id
 * @property string $enrolment_id
 * @property string|null $drawing_path
 * @property string|null $drawing_sha256
 * @property Carbon $signed_at
 * @property string $source
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AttendanceSignature extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance_signature';

    public const ONLINE = 'online';

    protected $table = 'attendance_signatures';

    /** @var list<string> */
    protected array $auditIgnore = ['drawing_sha256'];

    /** @var list<string> */
    protected array $auditPersonal = ['drawing_path', 'ip', 'user_agent'];

    /** @var list<string> */
    protected $fillable = ['slot_id', 'enrolment_id', 'drawing_path', 'drawing_sha256', 'signed_at', 'source', 'ip', 'user_agent'];

    /** @var array<string, mixed> */
    protected $attributes = ['source' => self::ONLINE];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
