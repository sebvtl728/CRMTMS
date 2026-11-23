<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A remote catch-up of the online signing (table attendance_catch_up_links):
 * a personal link (only its SHA-256 kept, 7 days, single use) sent by e-mail
 * to one trainee for one half-day, then a 6-digit e-mailed code (HMAC). One
 * active link (neither used nor revoked) per half-day and trainee. The
 * address is personal: never journaled, erased by the anonymisation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $slot_id
 * @property string $enrolment_id
 * @property string|null $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property string|null $code_hash
 * @property Carbon|null $code_expires_at
 * @property int $code_attempts
 * @property Carbon|null $code_sent_at
 * @property string|null $email_status
 * @property string|null $email_error_code
 * @property string|null $created_by
 * @property list<string>|null $extra_slot_ids the other half-days of a grouped catch-up
 */
class CatchUpLink extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance_catch_up_link';

    protected $table = 'attendance_catch_up_links';

    /** @var list<string> */
    protected array $auditIgnore = ['token_hash', 'code_hash', 'code_expires_at', 'code_attempts', 'code_sent_at', 'email_status', 'email_error_code'];

    /** @var list<string> */
    protected array $auditPersonal = ['email'];

    /** @var list<string> */
    protected $fillable = ['slot_id', 'enrolment_id', 'email', 'token_hash', 'expires_at', 'created_by', 'extra_slot_ids'];

    /** @var array<string, mixed> */
    protected $attributes = ['code_attempts' => 0];

    protected function casts(): array
    {
        return [
            'extra_slot_ids' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'code_expires_at' => 'datetime',
            'code_sent_at' => 'datetime',
            'code_attempts' => 'integer',
        ];
    }

    /**
     * Every half-day of the link: its slot, then those of a grouped catch-up.
     *
     * @return list<string>
     */
    public function slotIds(): array
    {
        return [(string) $this->slot_id, ...array_map('strval', $this->extra_slot_ids ?? [])];
    }

    public function isActive(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
