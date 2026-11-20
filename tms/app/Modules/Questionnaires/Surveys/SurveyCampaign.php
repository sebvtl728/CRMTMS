<?php

namespace App\Modules\Questionnaires\Surveys;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The questionnaire of a session for one type (hot / cold) and one public
 * (trainee / client): its frozen questions, its session QR link (hot
 * trainees only; sha256 kept, the plain token encrypted to show the QR
 * again) and the last day it accepts answers (Paris).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property string|null $template_id
 * @property string $kind
 * @property string $audience
 * @property string $title
 * @property list<array<string, mixed>> $questions
 * @property string|null $qr_token_hash
 * @property string|null $qr_token_encrypted
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $closes_on
 * @property string|null $launched_by
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SurveyInvitation> $invitations
 */
class SurveyCampaign extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'survey_campaign';

    public const HOT = 'hot';

    public const COLD = 'cold';

    public const TRAINEE = 'trainee';

    public const CLIENT = 'client';

    /** @var list<string> */
    protected array $auditIgnore = ['questions', 'qr_token_hash', 'qr_token_encrypted', 'launched_by'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'template_id', 'kind', 'audience', 'title', 'questions', 'qr_token_hash', 'qr_token_encrypted', 'opened_at', 'closes_on', 'launched_by'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'opened_at' => 'immutable_datetime', 'closes_on' => 'immutable_date'];
    }

    /** @return HasMany<SurveyInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class, 'campaign_id');
    }

    /** Answers are accepted until the end of closes_on (Paris). */
    public function isOpen(): bool
    {
        return CarbonImmutable::now('Europe/Paris')->toDateString() <= $this->closes_on->toDateString();
    }

    public static function findByQrToken(string $plain): ?self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $plain) !== 1) {
            return null;
        }
        $hash = hash('sha256', $plain);
        $campaign = self::query()->where('qr_token_hash', $hash)->first();

        return $campaign !== null && hash_equals((string) $campaign->qr_token_hash, $hash) ? $campaign : null;
    }
}
