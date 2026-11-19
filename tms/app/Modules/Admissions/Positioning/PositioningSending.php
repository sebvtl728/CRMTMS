<?php

namespace App\Modules\Admissions\Positioning;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A positioning test sent to a candidate: the e-mail, the tests (versions
 * of the library, positioning_sending_versions) and the personal upload
 * link, of which only the sha256 is kept. The link works until expires_at,
 * unless revoked (a newer sending) or already used (submitted_at).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property string|null $email
 * @property string|null $subject
 * @property string|null $message
 * @property string $token_hash
 * @property string|null $token_encrypted the plain token, encrypted (APP_KEY): the link shown on the candidature
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $submitted_at
 * @property string $email_status pending | sent | failed | none (a link to copy, no e-mail)
 * @property string|null $email_error
 * @property CarbonImmutable|null $sent_at
 * @property string|null $created_by
 * @property-read Candidature|null $candidature
 */
class PositioningSending extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'positioning_sending';

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** A link generated to be copied, no e-mail. */
    public const NO_EMAIL = 'none';

    /** @var list<string> */
    protected array $auditPersonal = ['email', 'subject', 'message'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'token_hash', 'token_encrypted', 'email_error'];

    /** @var list<string> */
    protected $fillable = ['candidature_id', 'email', 'subject', 'message', 'token_hash', 'token_encrypted', 'expires_at', 'email_status', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Candidature, $this> */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    /** @return list<string> the versions sent, in their order */
    public function versionIds(): array
    {
        return DB::table('positioning_sending_versions')->where('positioning_sending_id', $this->id)
            ->orderBy('position')->pluck('brochure_version_id')->map(fn ($id) => (string) $id)->all();
    }

    /** Whether the link still works now (not revoked, not used, not expired). */
    public function linkActive(): bool
    {
        return $this->revoked_at === null && $this->submitted_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** The sending of a plain token, whatever its state (null: unknown or malformed). */
    public static function findByToken(string $plain): ?self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $plain) !== 1) {
            return null;
        }
        $hash = self::hashToken($plain);
        $sending = self::query()->where('token_hash', $hash)->first();

        return $sending !== null && hash_equals($sending->token_hash, $hash) ? $sending : null;
    }
}
