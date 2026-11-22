<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The attendance manager's side of the catch-ups (2026-11-21), under
 * sessions.attendance.manage (route) on a scope other than « Ses séances »
 * reaching the session, 404 otherwise: confirm, refuse, see the drawing.
 */
class CatchUpController extends Controller
{
    public function __construct(private readonly CatchUpConfirmation $confirmation) {}

    public function confirm(Request $request, string $signature): JsonResponse
    {
        $this->confirmation->confirm(self::signature($request, $signature), self::user($request));

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store, private');
    }

    public function refuse(Request $request, string $signature): JsonResponse
    {
        $this->confirmation->refuse(self::signature($request, $signature), self::user($request), (string) $request->input('reason', ''));

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store, private');
    }

    public function drawing(Request $request, string $signature): Response
    {
        $found = self::signature($request, $signature);
        $disk = Storage::disk(TenantStorage::DISK);
        abort_if($found->drawing_path === null || ! $disk->exists($found->drawing_path), 404);

        return response((string) $disk->get($found->drawing_path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A catch-up signature of a session the manager reaches, else 404. */
    private static function signature(Request $request, string $id): AttendanceSignature
    {
        abort_unless(Str::isUuid($id), 404);
        $signature = AttendanceSignature::query()->find($id) ?? abort(404);
        abort_if($signature->kind === AttendanceSignature::KIND_ON_TIME, 404);
        $sessionId = (string) SignSlot::query()->whereKey($signature->slot_id)->value('training_session_id');
        $user = self::user($request);
        abort_if(Scopes::of($user, Permissions::ATTENDANCE_MANAGE) === AccessScope::Trainer, 404);
        abort_unless(SessionsVisibility::sessions(TrainingSession::query()->whereKey($sessionId), $user, Permissions::ATTENDANCE_MANAGE)->exists(), 404);

        return $signature;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
