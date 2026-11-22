<?php

namespace App\Modules\Sessions\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proofs of an absence on a séance: an attendance manager whose scope is not
 * « Ses séances » (a trainer never reads a medical certificate); the séance
 * within the scope, the enrolment on its session, else 404.
 */
class AttendanceProofController extends Controller
{
    public function __construct(private readonly AttendanceProofs $proofs) {}

    public function store(Request $request, Seance $seance, string $proofEnrolment): JsonResponse
    {
        $user = self::authorize($request, $seance);
        $enrolment = $proofEnrolment;
        abort_unless(Str::isUuid($enrolment) && Enrolment::query()->whereKey($enrolment)->where('training_session_id', $seance->training_session_id)->exists(), 404);
        Validator::make($request->all(), AttendanceScans::rules(), [
            ...AttendanceScans::messages(),
            AttendanceScans::FIELD.'.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
            AttendanceScans::FIELD.'.uploaded' => 'Le justificatif n\'a pas pu être envoyé (taille maximale : 10 Mo).',
        ])->validate();
        $file = $request->file(AttendanceScans::FIELD);
        assert($file instanceof UploadedFile);
        $this->proofs->store($seance, $enrolment, $file, (string) $user->getKey());

        return self::json(['proofs' => AttendanceProofs::forSeance($seance)], 201);
    }

    public function show(Request $request, Seance $seance, string $proof): StreamedResponse
    {
        self::authorize($request, $seance);
        $row = self::find($seance, $proof);
        $disk = Storage::disk(TenantStorage::DISK);
        abort_unless($disk->exists($row->path), 404);

        return response()->stream(function () use ($disk, $row): void {
            $stream = $disk->readStream($row->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $row->mime,
            'Content-Disposition' => 'attachment; filename="'.$row->original_name.'"',
            'Content-Length' => (string) $disk->size($row->path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Seance $seance, string $proof): JsonResponse
    {
        self::authorize($request, $seance);
        $this->proofs->delete(self::find($seance, $proof));

        return self::json(['proofs' => AttendanceProofs::forSeance($seance)]);
    }

    /** sessions.attendance.manage with a scope other than « Ses séances ». */
    public static function canManage(User $user): bool
    {
        $scope = Scopes::of($user, Permissions::ATTENDANCE_MANAGE);

        return $scope !== null && $scope !== AccessScope::Trainer;
    }

    private static function find(Seance $seance, string $proof): AttendanceProof
    {
        abort_unless(Str::isUuid($proof), 404);

        return AttendanceProof::query()->where('seance_id', $seance->id)->whereKey($proof)->firstOrFail();
    }

    private static function authorize(Request $request, Seance $seance): User
    {
        $user = $request->user();
        assert($user instanceof User);
        abort_unless(SessionsVisibility::seances(Seance::query()->whereKey($seance->id), $user, Permissions::ATTENDANCE_MANAGE)->exists(), 404);
        abort_unless(self::canManage($user), 403);

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
