<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
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
    public function __construct(private readonly AttendanceSigning $signing, private readonly RemoteCatchUp $remote) {}

    public function show(Request $request, string $token): Response
    {
        $slot = AttendanceSigning::findByToken($token);

        return $slot === null ? self::invalid($request) : self::page($request, $slot, url('/emargement/'.$token));
    }

    /** The on-site catch-up of a past half-day: the same page, its own link. */
    public function showCatchUp(Request $request, string $token): Response
    {
        $slot = OnsiteCatchUp::find($token);

        return $slot === null ? self::invalid($request)
            : self::page($request, $slot, url('/emargement/r/'.$token), ['mode' => 'catch_up_onsite', 'banner' => OnsiteCatchUp::banner($slot)]);
    }

    public function storeCatchUp(Request $request, string $token): JsonResponse
    {
        $slot = OnsiteCatchUp::find($token) ?? abort(404);
        $this->signing->sign($slot, (string) $request->input('enrolment_id', ''), (string) $request->input('drawing', ''),
            (string) $request->ip(), (string) $request->userAgent(), requireOpen: false, kind: AttendanceSignature::KIND_ONSITE);

        return response()->json(['signed' => true], 201)->header('Cache-Control', 'no-store, private');
    }

    /** The remote catch-up of one trainee: her first name only, the code by e-mail. */
    public function showRemote(Request $request, string $token): Response
    {
        $link = RemoteCatchUp::find($token);
        if ($link === null) {
            return self::invalid($request);
        }
        $slot = SignSlot::query()->findOrFail($link->slot_id);
        $session = TrainingSession::query()->findOrFail($slot->training_session_id);
        $personId = (string) Enrolment::query()->whereKey($link->enrolment_id)->value('person_id');
        $person = app(Fiches::class)->personLabels([$personId])[$personId] ?? null;
        $response = Inertia::render('public/attendance-signing', [
            'state' => 'open',
            'mode' => 'catch_up_remote',
            'banner' => OnsiteCatchUp::banner($slot),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'session' => PlanningRows::sessionLabels([$session])[$session->id] ?? $session->number,
            'date' => $slot->date->locale('fr')->isoFormat('dddd D MMMM YYYY'),
            'half_label' => SignSlot::label($slot->half),
            'trainee_first_name' => trim((string) ($person['first_name'] ?? '')),
            'code_url' => url('/emargement/l/'.$token.'/code'),
            'post_url' => url('/emargement/l/'.$token),
        ])->toResponse($request);
        $response->headers->add(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }

    public function codeRemote(Request $request, string $token): JsonResponse
    {
        $this->remote->sendCode(RemoteCatchUp::find($token) ?? abort(404));

        return response()->json(['sent' => true])->header('Cache-Control', 'no-store, private');
    }

    public function storeRemote(Request $request, string $token): JsonResponse
    {
        $link = RemoteCatchUp::find($token) ?? abort(404);
        $this->remote->sign($link, (string) $request->input('code', ''), (string) $request->input('drawing', ''), (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['signed' => true], 201)->header('Cache-Control', 'no-store, private');
    }

    private static function invalid(Request $request): Response
    {
        return Inertia::render('public/attendance-signing', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
    }

    /**
     * The trainees expected and not signed yet (« Léa D. »), any kind of signature counting.
     *
     * @param  array<string, mixed>  $extra
     */
    private static function page(Request $request, SignSlot $slot, string $postUrl, array $extra = []): Response
    {
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
            'mode' => 'on_time',
            'banner' => null,
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'session' => PlanningRows::sessionLabels([$session])[$session->id] ?? $session->number,
            'date' => $slot->date->locale('fr')->isoFormat('dddd D MMMM YYYY'),
            'half_label' => SignSlot::label($slot->half),
            'trainees' => $trainees,
            // Nobody expected (entry date later, no enrolment): not « everyone has signed ».
            'expected' => count($names),
            'post_url' => $postUrl,
            ...$extra,
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
