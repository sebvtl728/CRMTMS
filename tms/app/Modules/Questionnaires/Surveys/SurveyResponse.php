<?php

namespace App\Modules\Questionnaires\Surveys;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The answers of one invitation (question id => 1-5, option or text) and
 * its score: the mean of the ratings × 100 (SurveyScoring), null without a
 * rating. Never audited nor logged (free text).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invitation_id
 * @property array<string, int|string|null> $answers
 * @property int|null $score
 * @property CarbonImmutable $submitted_at
 */
class SurveyResponse extends Model
{
    use BelongsToTenant, HasUuids;

    /** @var list<string> */
    protected $fillable = ['invitation_id', 'answers', 'score', 'submitted_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'integer', 'submitted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SurveyInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class, 'invitation_id');
    }
}
