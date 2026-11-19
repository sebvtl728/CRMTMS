<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Positioning\PositioningSending;
use App\Modules\Admissions\Positioning\PositioningUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The candidate's side of an online test (public link, no account): the
 * test as shown (never the right answers nor the tolerances), start,
 * answers, traces and submission. Any invalid link: 404.
 */
class OnlinePassage extends Controller
{
    public function __construct(private readonly OnlineAttempts $attempts) {}

    /**
     * The props of the page: the questions without their answers, and the
     * attempt (answers, seconds left) once started.
     *
     * @return array<string, mixed>
     */
    public static function props(PositioningSending $sending): array
    {
        $attempts = app(OnlineAttempts::class);
        $version = $attempts->version($sending);
        $attempt = $attempts->attempt($sending);
        $test = OnlineTest::withTrashed()->find($version->online_test_id);

        return [
            'title' => (string) ($test->title ?? 'Test de positionnement'),
            'time_limit_minutes' => $version->time_limit_minutes,
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'],
                'intro' => $section['intro'] ?? null,
                'questions' => array_map(fn (array $q) => array_intersect_key($q, array_flip(['id', 'type', 'label', 'support_text', 'image', 'choices', 'required'])), $section['questions']),
            ], $version->content['sections'] ?? []),
            'attempt' => $attempt === null ? null : [
                'answers' => (object) $attempt->answers,
                'remaining_seconds' => OnlineAttempts::remaining($attempt),
                'submitted' => $attempt->submitted_at !== null,
            ],
        ];
    }

    public function start(string $token): JsonResponse
    {
        $attempt = $this->attempts->start($this->sending($token, open: true));

        return response()->json(['remaining_seconds' => OnlineAttempts::remaining($attempt), 'answers' => (object) $attempt->answers]);
    }

    public function answers(Request $request, string $token): JsonResponse
    {
        $answers = $request->input('answers');
        abort_unless(is_array($answers), 422);
        $attempt = $this->attempts->save($this->sending($token), $answers);

        return response()->json(['saved' => true, 'remaining_seconds' => OnlineAttempts::remaining($attempt)]);
    }

    public function event(Request $request, string $token): JsonResponse
    {
        $this->attempts->event($this->sending($token), (string) $request->input('type'));

        return response()->json(['ok' => true]);
    }

    public function submit(string $token): JsonResponse
    {
        $this->attempts->submit($this->sending($token));

        return response()->json(['status' => 'Merci, vos réponses ont bien été envoyées.']);
    }

    /** The sending of a working link to an online test (a submitted one too: the service answers 409). */
    private function sending(string $token, bool $open = false): PositioningSending
    {
        $sending = PositioningUploads::usable($token, allowSubmitted: ! $open);
        abort_if($sending === null || $sending->online_test_version_id === null, 404);

        return $sending;
    }
}
