<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The online test on the candidature: the latest attempt as the page shows
 * it (answers next to the right ones, points earned, traces), and the hand
 * grading of the open questions (integer from 0 to the question's points),
 * which gives the final score once nothing is left to grade.
 */
final class OnlineReview
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array<string, mixed>|null
     */
    public static function forPage(Candidature $c, bool $canGrade): ?array
    {
        $attempt = OnlineAttempt::query()->where('candidature_id', $c->id)->latest('started_at')->first();

        return $attempt === null ? null : self::view($attempt, $canGrade);
    }

    /**
     * An attempt as shown (page and PDF).
     *
     * @return array<string, mixed>
     */
    public static function view(OnlineAttempt $attempt, bool $canGrade = false): array
    {
        $version = OnlineTestVersion::query()->findOrFail($attempt->online_test_version_id);
        $test = OnlineTest::withTrashed()->find($version->online_test_id);
        $grade = OnlineGrading::grade($version->content, $attempt->answers, $attempt->manual_scores);
        $events = collect($attempt->events);

        return [
            'id' => $attempt->id,
            'title' => (string) ($test->title ?? 'Test en ligne'),
            'version' => $version->number,
            'started_at' => $attempt->started_at->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'minutes' => $attempt->submitted_at === null ? null : (int) ceil($attempt->started_at->diffInSeconds($attempt->submitted_at) / 60),
            'auto_score' => $attempt->auto_score,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'exits' => $events->where('type', 'blur')->count(),
            'blocked_pastes' => $events->where('type', 'paste_blocked')->count(),
            'pending' => $attempt->submitted_at === null ? [] : $grade['pending'],
            'can_grade' => $canGrade && $attempt->submitted_at !== null,
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'],
                'questions' => array_map(fn (array $q) => [
                    'id' => $q['id'],
                    'label' => $q['label'],
                    'type' => $q['type'],
                    'points' => (int) $q['points'],
                    'given' => self::shown($q, $attempt->answers[$q['id']] ?? null),
                    'right' => OnlineGrading::automatic($q) ? self::shown($q, $q['answer']) : null,
                    'earned' => OnlineGrading::points($q, $attempt->answers[$q['id']] ?? null) ?? ($attempt->manual_scores[$q['id']] ?? null),
                    'manual' => OnlineGrading::manual($q),
                ], $section['questions']),
            ], $version->content['sections'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $scores  question id => points
     */
    public function grade(Candidature $candidature, OnlineAttempt $attempt, array $scores, User $by): OnlineAttempt
    {
        abort_if($attempt->candidature_id !== $candidature->id, 404);

        return DB::transaction(function () use ($candidature, $attempt, $scores): OnlineAttempt {
            /** @var OnlineAttempt $locked */
            $locked = OnlineAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->submitted_at === null) {
                throw new UserFacingHttpException(409, 'Le candidat n\'a pas encore envoyé ses réponses.');
            }
            $version = OnlineTestVersion::query()->findOrFail($locked->online_test_version_id);
            $questions = $version->questions();
            $errors = [];
            $clean = [];
            foreach ($scores as $id => $points) {
                $question = $questions[(string) $id] ?? null;
                if ($question === null || ! OnlineGrading::manual($question)) {
                    $errors["scores.{$id}"] = 'Cette question se corrige seule.';

                    continue;
                }
                if (! is_numeric($points) || (int) $points != $points || (int) $points < 0 || (int) $points > (int) $question['points']) {
                    $errors["scores.{$id}"] = 'La note dépasse le barème de la question.';

                    continue;
                }
                $clean[(string) $id] = (int) $points;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $manual = [...$locked->manual_scores, ...$clean];
            $grade = OnlineGrading::grade($version->content, $locked->answers, $manual);
            $locked->forceFill(['manual_scores' => $manual, 'score' => $grade['score']])->save();

            $result = OnlineAttempts::result($locked, $grade['pending'] !== []);
            Candidature::query()->lockForUpdate()->findOrFail($candidature->id)->forceFill(['positioning_result' => $result])->save();
            $this->audit->record('candidature.online_test_graded', $candidature, [], ['score' => $grade['score'], 'max' => $locked->max_score]);

            return $locked;
        });
    }

    /**
     * An answer (or a right answer) as text: choice labels, Vrai/Faux, Oui/Non.
     *
     * @param  array<string, mixed>  $q
     */
    private static function shown(array $q, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $labels = array_column($q['choices'] ?? [], 'label', 'id');

        return match ($q['type']) {
            'single_choice' => $labels[(string) $value] ?? null,
            'multiple_choice' => implode(', ', array_map(fn ($id) => $labels[(string) $id] ?? '?', (array) $value)),
            'true_false' => $value === true ? 'Vrai' : 'Faux',
            'yes_no' => $value === true ? 'Oui' : 'Non',
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
