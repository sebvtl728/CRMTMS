<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The trainer's side of the online signing, all under
 * sessions.attendance.manage (route): a half-day is reached when one of its
 * séances is within the scope (« Ses séances »: one of his own), 404
 * otherwise; its validation needs every séance (SlotValidation).
 */
class SigningController extends Controller
{
    public function __construct(private readonly AttendanceSigning $signing, private readonly SlotValidation $validation) {}

    /** Opens (or renews) a half-day of the séance's day; the séance reached, else 404. */
    public function open(Request $request, Seance $seance): JsonResponse
    {
        $user = self::user($request);
        abort_unless(self::reaches($user, [$seance->id]), 404);
        $data = $request->validate(['half' => ['required', 'in:'.implode(',', SignSlot::HALVES)]], ['half.*' => 'Demi-journée inconnue.']);
        $date = $seance->date->toDateString();
        $seances = SlotSeances::of($seance->training_session_id, $date, $data['half']);
        abort_unless($seances->isEmpty() || self::reaches($user, $seances->pluck('id')->all()), 404);
        $session = TrainingSession::query()->findOrFail($seance->training_session_id);
        ['slot' => $slot, 'token' => $token] = $this->signing->open($session, $date, $data['half'], $user);

        return self::json(['slot_id' => $slot->id, 'url' => route('sessions.signing.show', $slot->id), 'public_url' => SlotQr::publicUrl($token)], 201);
    }

    public function show(Request $request, string $slot): Response
    {
        $found = self::slot($request, $slot);

        return Inertia::render('sessions/attendance-signing', ['state' => SlotRows::state($found)]);
    }

    public function state(Request $request, string $slot): JsonResponse
    {
        return self::json(SlotRows::state(self::slot($request, $slot)));
    }

    public function close(Request $request, string $slot): JsonResponse
    {
        $found = self::slot($request, $slot);
        $this->signing->close($found);

        return self::json(SlotRows::state($found->fresh() ?? $found));
    }

    public function erase(Request $request, string $slot, string $signature): JsonResponse
    {
        $found = self::slot($request, $slot);
        abort_unless(Str::isUuid($signature), 404);
        $this->signing->erase(AttendanceSignature::query()->where('slot_id', $found->id)->findOrFail($signature));

        return self::json(SlotRows::state($found));
    }

    public function validate(Request $request, string $slot): JsonResponse
    {
        $found = self::slot($request, $slot);
        $done = $this->validation->validate($found, (string) $request->input('drawing', ''), self::user($request));

        return self::json(SlotRows::state($done));
    }

    /** The slot reached by the user (one of its séances within the scope of sessions.attendance.manage), else 404. */
    private static function slot(Request $request, string $id): SignSlot
    {
        abort_unless(Str::isUuid($id), 404);
        $slot = SignSlot::query()->find($id) ?? abort(404);
        $ids = SlotSeances::ofSlot($slot)->pluck('id')->all();
        abort_unless(TrainingSession::query()->whereKey($slot->training_session_id)->exists(), 404);
        abort_unless(self::reaches(self::user($request), $ids), 404);

        return $slot;
    }

    /**
     * @param  list<string>  $seanceIds
     */
    private static function reaches(User $user, array $seanceIds): bool
    {
        return $seanceIds !== [] && SessionsVisibility::seances(Seance::query()->whereKey($seanceIds), $user, Permissions::ATTENDANCE_MANAGE)->exists();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
