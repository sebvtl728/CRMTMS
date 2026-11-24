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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The drawing of a trainee's signature (any kind), for the individual
 * follow-up: sessions.attendance.manage (route) on a scope other than
 * « Ses séances » reaching the session, 404 otherwise. Never cached.
 */
class SignatureDrawingController extends Controller
{
    public function __invoke(Request $request, string $signature): Response
    {
        abort_unless(Str::isUuid($signature), 404);
        $found = AttendanceSignature::query()->find($signature) ?? abort(404);
        $user = $request->user();
        assert($user instanceof User);
        $sessionId = (string) SignSlot::query()->whereKey($found->slot_id)->value('training_session_id');
        abort_if(Scopes::of($user, Permissions::ATTENDANCE_MANAGE) === AccessScope::Trainer, 404);
        abort_unless(SessionsVisibility::sessions(TrainingSession::withTrashed()->whereKey($sessionId), $user, Permissions::ATTENDANCE_MANAGE)->exists(), 404);
        $disk = Storage::disk(TenantStorage::DISK);
        abort_if($found->drawing_path === null || ! $disk->exists($found->drawing_path), 404);

        return response((string) $disk->get($found->drawing_path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
