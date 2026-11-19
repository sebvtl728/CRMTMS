<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Positioning\PositioningSending;
use App\Modules\Core\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The candidate's passage of an online test through his link: one attempt
 * per link, started once (the timer runs from started_at), answers saved
 * as typed until submission or the time limit (plus GRACE_SECONDS for the
 * last save in flight), traces appended. Past the limit, any request
 * submits the attempt. Submission grades it (OnlineGrading), closes the
 * link, fills « Reçu le », « Score ou niveau » and moves the stage to
 * « Test reçu » from an earlier stage of the path (never back).
 */
final class OnlineAttempts
{
    public const GRACE_SECONDS = 30;

    public const EVENTS = ['blur', 'focus', 'paste_blocked', 'copy_blocked'];

    public const MAX_EVENTS = 500;

    public const TIME_UP = 'Le temps est écoulé : vos réponses ont été envoyées.';

    public const SUBMITTED = 'Vos réponses ont déjà été envoyées.';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function version(PositioningSending $sending): OnlineTestVersion
    {
        return OnlineTestVersion::query()->findOrFail($sending->online_test_version_id);
    }

    public function attempt(PositioningSending $sending): ?OnlineAttempt
    {
        return OnlineAttempt::query()->where('positioning_sending_id', $sending->id)->first();
    }

    /** Starts the attempt (once; a second call returns the same one). */
    public function start(PositioningSending $sending): OnlineAttempt
    {
        return DB::transaction(function () use ($sending): OnlineAttempt {
            PositioningSending::query()->lockForUpdate()->findOrFail($sending->id);
            $existing = $this->attempt($sending);
            if ($existing !== null) {
                return $existing;
            }
            $version = $this->version($sending);
            $now = CarbonImmutable::now();

            return OnlineAttempt::query()->create([
                'positioning_sending_id' => $sending->id,
                'candidature_id' => $sending->candidature_id,
                'online_test_version_id' => $version->id,
                'started_at' => $now,
                'deadline_at' => $version->time_limit_minutes !== null ? $now->addMinutes($version->time_limit_minutes) : null,
                'max_score' => $version->total_points,
            ]);
        });
    }

    /** Seconds left (null: no limit). */
    public static function remaining(OnlineAttempt $attempt): ?int
    {
        return $attempt->deadline_at === null ? null : max(0, (int) CarbonImmutable::now()->diffInSeconds($attempt->deadline_at, false));
    }

    /**
     * Merges answers (question id => value), checked against the version.
     *
     * @param  array<string, mixed>  $answers
     */
    public function save(PositioningSending $sending, array $answers): OnlineAttempt
    {
        return $this->locked($sending, function (OnlineAttempt $attempt, OnlineTestVersion $version) use ($answers): OnlineAttempt {
            $questions = $version->questions();
            $clean = [];
            foreach ($answers as $id => $value) {
                $question = $questions[(string) $id] ?? throw ValidationException::withMessages(['answers' => 'Question inconnue.']);
                $clean[(string) $id] = self::checked($question, $value);
            }
            $attempt->answers = [...$attempt->answers, ...$clean];
            $attempt->save();

            return $attempt;
        });
    }

    public function event(PositioningSending $sending, string $type): void
    {
        if (! in_array($type, self::EVENTS, true)) {
            throw ValidationException::withMessages(['type' => 'Évènement inconnu.']);
        }
        $this->locked($sending, function (OnlineAttempt $attempt) use ($type): void {
            if (count($attempt->events) < self::MAX_EVENTS) {
                $attempt->events = [...$attempt->events, ['type' => $type, 'at' => CarbonImmutable::now()->toIso8601String()]];
                $attempt->save();
            }
        });
    }

    public function submit(PositioningSending $sending): OnlineAttempt
    {
        return DB::transaction(function () use ($sending): OnlineAttempt {
            $attempt = OnlineAttempt::query()->where('positioning_sending_id', $sending->id)->lockForUpdate()->first()
                ?? throw new UserFacingHttpException(409, 'Commencez le test avant de l\'envoyer.');
            if ($attempt->submitted_at !== null) {
                throw new UserFacingHttpException(409, self::SUBMITTED);
            }

            return $this->close($attempt);
        });
    }

    /**
     * Runs $write on the open attempt under its lock; past the time limit
     * (and the grace), submits it and refuses with TIME_UP.
     *
     * @template T
     *
     * @param  callable(OnlineAttempt, OnlineTestVersion): T  $write
     * @return T
     */
    private function locked(PositioningSending $sending, callable $write): mixed
    {
        $timeUp = false;
        $result = DB::transaction(function () use ($sending, $write, &$timeUp): mixed {
            $attempt = OnlineAttempt::query()->where('positioning_sending_id', $sending->id)->lockForUpdate()->first()
                ?? throw new UserFacingHttpException(409, 'Commencez le test avant de répondre.');
            if ($attempt->submitted_at !== null) {
                throw new UserFacingHttpException(409, self::SUBMITTED);
            }
            if ($attempt->deadline_at !== null && CarbonImmutable::now()->greaterThan($attempt->deadline_at->addSeconds(self::GRACE_SECONDS))) {
                $this->close($attempt);
                $timeUp = true;

                return null;
            }

            return $write($attempt, $this->version($sending));
        });
        if ($timeUp) {
            throw new UserFacingHttpException(409, self::TIME_UP);
        }

        return $result;
    }

    /** Grades and closes the attempt, the link and the candidature's test (inside a transaction). */
    private function close(OnlineAttempt $attempt): OnlineAttempt
    {
        $version = OnlineTestVersion::query()->findOrFail($attempt->online_test_version_id);
        $grade = OnlineGrading::grade($version->content, $attempt->answers, $attempt->manual_scores);
        $now = CarbonImmutable::now();
        $attempt->forceFill(['submitted_at' => $now, 'auto_score' => $grade['auto'], 'score' => $grade['score']])->save();
        PositioningSending::query()->whereKey($attempt->positioning_sending_id)->toBase()->update(['submitted_at' => $now, 'updated_at' => $now]);

        /** @var Candidature $candidature */
        $candidature = Candidature::query()->lockForUpdate()->findOrFail($attempt->candidature_id);
        $changes = ['positioning_result' => self::result($attempt, $grade['pending'] !== [])];
        if ($candidature->positioning_received_on === null) {
            $changes['positioning_received_on'] = CarbonImmutable::now('Europe/Paris')->toDateString();
        }
        $rank = $candidature->status->rank();
        if ($rank !== null && $rank < (int) CandidatureStatus::PositioningReceived->rank()) {
            $changes['status'] = CandidatureStatus::PositioningReceived->value;
        }
        $candidature->forceFill($changes)->save();
        $this->audit->record('candidature.online_test_submitted', $candidature, [], ['score' => $attempt->score ?? $attempt->auto_score, 'max' => $attempt->max_score]);

        return $attempt;
    }

    /** « 12 / 20 », or « 8 / 20 (réponses rédigées à corriger) » while hand-graded questions are left. */
    public static function result(OnlineAttempt $attempt, bool $pending): string
    {
        $score = $pending ? (int) $attempt->auto_score : (int) $attempt->score;

        return $score.' / '.$attempt->max_score.($pending ? ' (réponses rédigées à corriger)' : '');
    }

    /**
     * An answer in the shape of its question type (texts at most 20 000 characters).
     *
     * @param  array<string, mixed>  $question
     */
    private static function checked(array $question, mixed $value): mixed
    {
        $invalid = fn () => ValidationException::withMessages(['answers' => 'Réponse invalide.']);
        if ($value === null || $value === '') {
            return null;
        }

        return match ($question['type']) {
            'short_text', 'long_text', 'number' => is_string($value) || is_int($value) || is_float($value) ? mb_substr((string) $value, 0, 20000) : throw $invalid(),
            'true_false', 'yes_no' => is_bool($value) ? $value : throw $invalid(),
            'single_choice' => is_string($value) && in_array($value, array_column($question['choices'] ?? [], 'id'), true) ? $value : throw $invalid(),
            'multiple_choice' => is_array($value) && array_diff($value, array_column($question['choices'] ?? [], 'id')) === [] ? array_values(array_unique(array_map('strval', $value))) : throw $invalid(),
            default => throw $invalid(),
        };
    }
}
