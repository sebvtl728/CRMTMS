<?php

namespace App\Modules\Questionnaires\Surveys;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One recipient of a campaign (a trainee: enrolment_id; a client contact:
 * person only) and his personal link, of which only the sha256 is kept (the
 * plain token travels in the encrypted payload of the e-mail job). Answered
 * once (answered_at), by the e-mail link or the session QR. Not audited: the
 * address and the answers never reach the audit log.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string|null $enrolment_id
 * @property string $person_id
 * @property string|null $email
 * @property string $token_hash
 * @property string $email_status pending | sent | failed | none (no address)
 * @property string|null $email_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $answered_at
 * @property string|null $answered_via email | qr
 * @property-read SurveyCampaign $campaign
 * @property-read SurveyResponse|null $response
 */
class SurveyInvitation extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const NO_EMAIL = 'none';

    public const VIA_EMAIL = 'email';

    public const VIA_QR = 'qr';

    /** @var list<string> */
    protected $fillable = ['campaign_id', 'enrolment_id', 'person_id', 'email', 'token_hash', 'email_status', 'email_error', 'sent_at', 'answered_at', 'answered_via'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'answered_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SurveyCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SurveyCampaign::class, 'campaign_id');
    }

    /** @return HasOne<SurveyResponse, $this> */
    public function response(): HasOne
    {
        return $this->hasOne(SurveyResponse::class, 'invitation_id');
    }

    /**
     * A new personal token: [plain, sha256].
     *
     * @return array{0: string, 1: string}
     */
    public static function newToken(): array
    {
        $plain = bin2hex(random_bytes(32));

        return [$plain, self::hashToken($plain)];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByToken(string $plain): ?self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $plain) !== 1) {
            return null;
        }
        $hash = self::hashToken($plain);
        $invitation = self::query()->where('token_hash', $hash)->first();

        return $invitation !== null && hash_equals($invitation->token_hash, $hash) ? $invitation : null;
    }

    /** Not answered yet and its campaign still open. */
    public function linkActive(): bool
    {
        return $this->answered_at === null && $this->campaign->isOpen();
    }
}
