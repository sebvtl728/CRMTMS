<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The signature of a trainee on a half-day (table attendance_signatures),
 * one per slot and enrolment, written by AttendanceSigning only. The drawing
 * (PNG under the attendance_signatures area), the address and the browser
 * are personal: never journaled, erased by the anonymisation (signed_at
 * kept). source = online (the shared link) or the key of a connector.
 * kind = on_time, or a catch-up (on site / remote) that counts as
 * attendance only once confirmed by an attendance manager (2026-11-21).
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
 * @property string $kind
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_by
 * @property Carbon|null $refused_at
 * @property string|null $refused_by
 * @property string|null $refusal_reason
 */
class AttendanceSignature extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance_signature';

    public const ONLINE = 'online';

    public const KIND_ON_TIME = 'on_time';

    public const KIND_ONSITE = 'catch_up_onsite';

    public const KIND_REMOTE = 'catch_up_remote';

    protected $table = 'attendance_signatures';

    /** @var list<string> */
    protected array $auditIgnore = ['drawing_sha256'];

    /** @var list<string> */
    protected array $auditPersonal = ['drawing_path', 'ip', 'user_agent', 'refusal_reason'];

    /** @var list<string> */
    protected $fillable = ['slot_id', 'enrolment_id', 'drawing_path', 'drawing_sha256', 'signed_at', 'source', 'ip', 'user_agent', 'kind'];

    /** @var array<string, mixed> */
    protected $attributes = ['source' => self::ONLINE, 'kind' => self::KIND_ON_TIME];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'confirmed_at' => 'datetime', 'refused_at' => 'datetime'];
    }

    /**
     * The signatures that make attendance: on time, or a confirmed catch-up.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('kind', self::KIND_ON_TIME)->orWhereNotNull('confirmed_at'));
    }

    /** A catch-up neither confirmed nor refused yet. */
    public function isPendingCatchUp(): bool
    {
        return $this->kind !== self::KIND_ON_TIME && $this->confirmed_at === null && $this->refused_at === null;
    }
}
