<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * « Rattrapage de plusieurs demi-journées » of a session: the half-days a
 * trainee can still sign, one link for several, « Tout confirmer ». The
 * same right as a catch-up of one half-day (SlotRows::mayCatchUpOn: a
 * manager, never a trainer alone).
 */
class GroupCatchUpController extends Controller
{
    public function __construct(private readonly GroupCatchUp $group) {}

    public function halves(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $this->allow($request, $trainingSession);

        $enrolmentId = (string) $request->query('enrolment', '');
        $personId = Str::isUuid($enrolmentId) ? (string) Enrolment::query()->where('training_session_id', $trainingSession->id)->whereKey($enrolmentId)->value('person_id') : '';

        return response()->json([
            'halves' => GroupCatchUp::candidates($trainingSession, $enrolmentId),
            // The trainee's address, prefilled (changeable).
            'email' => $personId === '' ? null : (app(Fiches::class)->personEmails([$personId])[$personId] ?? null),
        ]);
    }

    public function send(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $user = $this->allow($request, $trainingSession);
        $halves = $request->input('halves');
        $count = $this->group->send($trainingSession, (string) $request->input('enrolment_id', ''), is_array($halves) ? array_values(array_filter($halves, 'is_array')) : [],
            (string) $request->input('email', ''), $user);

        return response()->json(['count' => $count]);
    }

    public function confirmAll(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $user = $this->allow($request, $trainingSession);

        return response()->json(['count' => $this->group->confirmAll($trainingSession, (string) $request->input('enrolment_id', ''), $user)]);
    }

    private function allow(Request $request, TrainingSession $session): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(SlotRows::mayCatchUpOn($session->id, $user), 403);

        return $user;
    }
}
