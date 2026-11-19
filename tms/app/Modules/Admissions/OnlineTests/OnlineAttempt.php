<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A candidate's attempt at an online test (one per link): answers by
 * question id, traces (exits, blocked pastes), automatic score, manual
 * scores of the hand-graded questions, final score once all are graded.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $positioning_sending_id
 * @property string $candidature_id
 * @property string $online_test_version_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $deadline_at
 * @property CarbonImmutable|null $submitted_at
 * @property array<string, mixed> $answers
 * @property list<array{type: string, at: string}> $events
 * @property int|null $auto_score
 * @property array<string, int> $manual_scores
 * @property int|null $score
 * @property int $max_score
 */
class OnlineAttempt extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'online_test_attempt';

    protected $table = 'online_test_attempts';

    /** @var list<string> */
    protected array $auditPersonal = ['answers', 'events', 'manual_scores'];

    /** @var list<string> */
    protected array $auditIgnore = ['answers', 'events'];

    /** @var list<string> */
    protected $fillable = ['positioning_sending_id', 'candidature_id', 'online_test_version_id', 'started_at', 'deadline_at', 'max_score'];

    protected $attributes = ['answers' => '{}', 'events' => '[]', 'manual_scores' => '{}'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'answers' => 'array',
            'events' => 'array',
            'manual_scores' => 'array',
            'auto_score' => 'integer',
            'score' => 'integer',
            'max_score' => 'integer',
        ];
    }
}
