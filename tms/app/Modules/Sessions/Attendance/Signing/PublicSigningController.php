<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Planning\PlanningRows;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The trainees' page of a half-day (no account; the host gives the
 * organisme): every invalid link - unknown, malformed, closed, validated,
 * another day, session cancelled or in the trash - answers the same page
 * (404). The page shows « Léa D. » labels of the trainees not signed yet,
 * nothing else of them.
 */
class PublicSigningController extends Controller
{
    public function __construct(private readonly AttendanceSigning $signing) {}

    public function show(Request $request, string $token): Response
    {
        $slot = AttendanceSigning::findByToken($token);
        if ($slot === null) {
            return Inertia::render('public/attendance-signing', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
        }
        $session = TrainingSession::query()->findOrFail($slot->training_session_id);
        $signed = AttendanceSignature::query()->where('slot_id', $slot->id)->pluck('enrolment_id')->all();
        $names = AttendanceSigning::publicNames(SlotSeances::expected($slot));
        $trainees = [];
        foreach ($names as $id => $label) {
            if (! in_array($id, $signed, true)) {
                $trainees[] = ['id' => $id, 'label' => $label];
            }
        }
        usort($trainees, fn (array $a, array $b) => strcmp(mb_strtolower($a['label']), mb_strtolower($b['label'])));

        $response = Inertia::render('public/attendance-signing', [
            'state' => 'open',
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'session' => PlanningRows::sessionLabels([$session])[$session->id] ?? $session->number,
            'date' => $slot->date->locale('fr')->isoFormat('dddd D MMMM YYYY'),
            'half_label' => SignSlot::label($slot->half),
            'trainees' => $trainees,
            // Nobody expected (entry date later, no enrolment): not « everyone has signed ».
            'expected' => count($names),
            'post_url' => url('/emargement/'.$token),
        ])->toResponse($request);
        $response->headers->add(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $slot = AttendanceSigning::findByToken($token) ?? abort(404);
        $this->signing->sign($slot, (string) $request->input('enrolment_id', ''), (string) $request->input('drawing', ''),
            (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['signed' => true], 201)->header('Cache-Control', 'no-store, private');
    }
}
