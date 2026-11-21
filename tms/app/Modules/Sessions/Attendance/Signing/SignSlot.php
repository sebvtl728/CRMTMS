<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A half-day to sign online (table attendance_sign_slots): a session, a
 * Paris day, morning or afternoon (cut at 13:00), written by
 * AttendanceSigning only. Open = its shared link works (only the SHA-256 of
 * the token is kept) on its own day; closed = link dead, signatures kept;
 * validated = signed by the trainer (or a manager), the attendance written.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property CarbonImmutable $date
 * @property string $half morning|afternoon
 * @property string $status open|closed|validated
 * @property string|null $token_hash
 * @property string|null $token_encrypted the token, encrypted, for the trainer's screen
 * @property Carbon $opened_at
 * @property string|null $opened_by
 * @property Carbon|null $validated_at
 * @property string|null $validated_by
 * @property string|null $trainer_name
 * @property string|null $validator_role trainer|manager
 * @property string|null $trainer_signature_path
 * @property string|null $trainer_signature_sha256
 * @property string|null $catch_up_token_hash the on-site catch-up link (SHA-256)
 * @property Carbon|null $catch_up_opened_at
 * @property string|null $catch_up_opened_by
 * @property Carbon|null $catch_up_expires_at end of the day the on-site catch-up was opened (Paris)
 */
class SignSlot extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance_sign_slot';

    public const MORNING = 'morning';

    public const AFTERNOON = 'afternoon';

    public const HALVES = [self::MORNING, self::AFTERNOON];

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const VALIDATED = 'validated';

    public const ROLE_TRAINER = 'trainer';

    public const ROLE_MANAGER = 'manager';

    protected $table = 'attendance_sign_slots';

    /** @var list<string> */
    protected array $auditIgnore = ['token_hash', 'token_encrypted', 'trainer_signature_sha256', 'catch_up_token_hash'];

    /** @var list<string> */
    protected array $auditPersonal = ['trainer_signature_path', 'trainer_name'];

    /** @var list<string> */
    protected $fillable = [
        'training_session_id', 'date', 'half', 'status', 'token_hash', 'token_encrypted', 'opened_at', 'opened_by',
        'validated_at', 'validated_by', 'trainer_name', 'validator_role', 'trainer_signature_path', 'trainer_signature_sha256',
        'catch_up_token_hash', 'catch_up_opened_at', 'catch_up_opened_by', 'catch_up_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'opened_at' => 'datetime',
            'validated_at' => 'datetime',
            'token_encrypted' => 'encrypted',
            'catch_up_opened_at' => 'datetime',
            'catch_up_expires_at' => 'datetime',
        ];
    }

    /** The shared token while the slot is open, else null. */
    public function token(): ?string
    {
        return $this->status === self::OPEN ? $this->token_encrypted : null;
    }

    public function isValidated(): bool
    {
        return $this->status === self::VALIDATED;
    }

    /** « Matin » / « Après-midi ». */
    public static function label(string $half): string
    {
        return $half === self::MORNING ? 'Matin' : 'Après-midi';
    }
}
