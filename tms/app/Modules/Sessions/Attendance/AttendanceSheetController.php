<?php

namespace App\Modules\Sessions\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
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
 * The attendance sheet of a séance (or of its day) and the signed scans
 * (spec § 4.8), all addressed through a séance reached by
 * sessions.attendance.manage within its scope (« Ses séances » for a
 * trainer: his séances only), 404 otherwise. A scan is read and deposited by
 * whoever reaches the séance; deleted by a manager (a trainer never, 403).
 * The day sheet only holds the séances the user reaches.
 */
class AttendanceSheetController extends Controller
{
    public function __construct(private readonly AttendanceSheetPdf $sheet, private readonly AttendanceScans $scans) {}

    /**
     * @throws PdfRenderingFailed
     */
    public function pdf(Request $request, Seance $seance): StreamedResponse
    {
        $user = self::authorizeSeance($request, $seance);
        $day = $request->boolean('day');
        $seances = $day
            ? SessionsVisibility::seances(Seance::query(), $user, Permissions::ATTENDANCE_MANAGE)
                ->where('training_session_id', $seance->training_session_id)->where('date', $seance->date->toDateString())->get()
            : collect([$seance]);
        $bytes = $this->sheet->render($seances);

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.AttendanceSheetPdf::fileName($seance, $day).'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Seance $seance): JsonResponse
    {
        $user = self::authorizeSeance($request, $seance);
        Validator::make($request->all(), AttendanceScans::rules(), AttendanceScans::messages())->validate();
        $file = $request->file(AttendanceScans::FIELD);
        assert($file instanceof UploadedFile);
        $this->scans->store($seance, $request->boolean('day'), $file, (string) $user->getKey());

        return self::json(self::list($seance, $user), 201);
    }

    public function show(Request $request, Seance $seance, string $scan): StreamedResponse
    {
        self::authorizeSeance($request, $seance);
        $row = self::find($seance, $scan);
        $disk = Storage::disk(TenantStorage::DISK);
        abort_unless($disk->exists($row->path), 404);
        $extension = pathinfo($row->path, PATHINFO_EXTENSION);

        return response()->stream(function () use ($disk, $row): void {
            $stream = $disk->readStream($row->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $row->mime,
            'Content-Disposition' => 'attachment; filename="emargement-'.$row->date->toDateString().'.'.$extension.'"',
            'Content-Length' => (string) $disk->size($row->path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Seance $seance, string $scan): JsonResponse
    {
        $user = self::authorizeSeance($request, $seance);
        abort_unless(self::canDelete($user), 403);
        $this->scans->delete(self::find($seance, $scan));

        return self::json(self::list($seance, $user));
    }

    /**
     * The scans of a séance (its own and its day's), for the attendance grid.
     *
     * @return array{scans: list<array<string, mixed>>, can_delete: bool}
     */
    public static function list(Seance $seance, User $user): array
    {
        return [
            'scans' => AttendanceScans::forSeance($seance)->get()->map(fn (AttendanceSheetScan $s): array => [
                'id' => $s->id,
                'scope' => $s->seance_id === null ? 'day' : 'seance',
                'name' => $s->original_name,
                'mime' => $s->mime,
                'size_bytes' => $s->size_bytes,
                'created_at' => $s->created_at?->toIso8601String(),
                'url' => route('seances.scans.show', [$seance->id, $s->id]),
            ])->all(),
            'can_delete' => self::canDelete($user),
        ];
    }

    /** sessions.attendance.manage with a scope other than « Ses séances ». */
    private static function canDelete(User $user): bool
    {
        $scope = Scopes::of($user, Permissions::ATTENDANCE_MANAGE);

        return $scope !== null && $scope !== AccessScope::Trainer;
    }

    private static function find(Seance $seance, string $scan): AttendanceSheetScan
    {
        abort_unless(Str::isUuid($scan), 404);

        return AttendanceScans::forSeance($seance)->whereKey($scan)->firstOrFail();
    }

    /**
     * sessions.attendance.manage (route) within its scope on the séance: 404 otherwise.
     */
    private static function authorizeSeance(Request $request, Seance $seance): User
    {
        $user = $request->user();
        assert($user instanceof User);
        abort_unless(SessionsVisibility::seances(Seance::query()->whereKey($seance->id), $user, Permissions::ATTENDANCE_MANAGE)->exists(), 404);

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
