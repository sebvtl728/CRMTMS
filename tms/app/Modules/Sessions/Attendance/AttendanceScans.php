<?php

namespace App\Modules\Sessions\Attendance;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Planning\Seance;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The signed, scanned attendance sheets (spec § 4.8): a PDF or an image
 * (PNG, JPEG) of 10 Mo at most, its type read from the bytes, never from what
 * the browser claims. Attached to a séance or to the day of its session
 * (seance_id null), under tenants/<tenant_id>/attendance_sheets/<session>/.
 * A scan is a proof: only deleted by a manager (controller) or by the
 * anonymisation of the only trainee of an individual session.
 */
final class AttendanceScans
{
    public const MAX_KILOBYTES = 10240;

    public const FIELD = 'file';

    public const TYPE_MESSAGE = 'Le scan doit être un fichier PDF ou une image (PNG, JPEG).';

    public const CANCELLED = 'Cette séance est annulée : on ne dépose plus de scan.';

    public const EMPTY_MESSAGE = 'Le fichier est vide.';

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [self::FIELD => [
            'bail', 'required', 'file', 'max:'.self::MAX_KILOBYTES,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                if ($value->getSize() === 0) {
                    $fail(self::EMPTY_MESSAGE);
                } elseif (self::detectedType($value) === null) {
                    $fail(self::TYPE_MESSAGE);
                }
            },
        ]];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            self::FIELD.'.required' => 'Choisissez un fichier PDF ou une image.',
            self::FIELD.'.file' => 'Le fichier envoyé est invalide.',
            self::FIELD.'.uploaded' => 'Le scan n\'a pas pu être envoyé (taille maximale : 10 Mo).',
            self::FIELD.'.max' => 'Le scan ne doit pas dépasser 10 Mo.',
        ];
    }

    /**
     * « pdf », « png » or « jpg » when the file really is what its name
     * claims, else null.
     */
    public static function detectedType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return null;
        }
        if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
            return (new \finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf'
                && file_get_contents($path, false, null, 0, 5) === '%PDF-' ? 'pdf' : null;
        }

        return CompanyBranding::detectedType($file);
    }

    private const MIMES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'];

    /**
     * @param  bool  $day  attached to the day of the session, not to the séance
     *
     * @throws ValidationException
     */
    public function store(Seance $seance, bool $day, UploadedFile $file, ?string $userId): AttendanceSheetScan
    {
        if ($seance->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED);
        }
        $type = self::detectedType($file) ?? throw ValidationException::withMessages([self::FIELD => self::TYPE_MESSAGE]);
        $date = $seance->date->toDateString();
        $path = TenantStorage::path('attendance_sheets', $seance->training_session_id.'/'.$date.'/'.Str::uuid7().'.'.$type);
        $disk = Storage::disk(TenantStorage::DISK);

        if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
            report(new RuntimeException('An attendance scan could not be written to the private disk.'));

            throw ValidationException::withMessages([self::FIELD => "Le scan n'a pas pu être enregistré. Réessayez ou contactez l'administrateur."]);
        }

        try {
            return DB::transaction(fn () => AttendanceSheetScan::query()->create([
                'training_session_id' => $seance->training_session_id,
                'seance_id' => $day ? null : $seance->id,
                'date' => $date,
                'path' => $path,
                // Neutral: the name typed by the sender may hold a trainee's name.
                'original_name' => self::neutralName($date, $type),
                'mime' => self::MIMES[$type],
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $userId,
            ]));
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /**
     * The scans that concern the séance: its own and those of its day.
     *
     * @return Builder<AttendanceSheetScan>
     */
    public static function forSeance(Seance $seance): Builder
    {
        return AttendanceSheetScan::query()->where('training_session_id', $seance->training_session_id)
            ->where('date', $seance->date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('seance_id')->orWhere('seance_id', $seance->id))
            ->orderBy('created_at')->orderBy('id');
    }

    public static function neutralName(string $date, string $type): string
    {
        return 'emargement-'.$date.'.'.$type;
    }

    /**
     * @throws UserFacingHttpException 409 when the month of the scan is closed
     */
    public function delete(AttendanceSheetScan $scan): void
    {
        Closures::assertOpen($scan->training_session_id, $scan->date->toDateString());
        DB::transaction(function () use ($scan): void {
            $scan->delete();
            $path = $scan->path;
            DB::afterCommit(fn () => Storage::disk(TenantStorage::DISK)->delete($path));
        });
    }

    /**
     * « Individual » sessions of the people (one trainee: the scans concern
     * him alone): the scans and their files are erased.
     *
     * @param  list<string>  $sessionIds
     */
    public static function purgeOfSessions(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }
        $paths = DB::table('attendance_sheet_scans')->whereIn('training_session_id', $sessionIds)->pluck('path')->all();
        DB::table('attendance_sheet_scans')->whereIn('training_session_id', $sessionIds)->delete();
        DB::afterCommit(function () use ($paths): void {
            Storage::disk(TenantStorage::DISK)->delete($paths);
        });
    }

    /**
     * Gives the scans of the sessions their neutral name again (anonymisation).
     *
     * @param  list<string>  $sessionIds
     */
    public static function neutraliseNames(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }
        DB::table('attendance_sheet_scans')->whereIn('training_session_id', $sessionIds)
            ->update(['original_name' => DB::raw("'emargement-' || date::text || '.' || regexp_replace(path, '^.*\\.', '')")]);
    }
}
