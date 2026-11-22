<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Planning\Seance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Proofs of an absence: same files as the scans (AttendanceScans::rules: PDF,
 * PNG or JPEG of 10 Mo at most, type read from the bytes), one or more per
 * séance and trainee. The name is neutral (the sender's may name the trainee
 * or the illness). A proof does not change the hours: it may be deposited
 * once the month is closed.
 */
final class AttendanceProofs
{
    private const MIMES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'];

    /**
     * @throws ValidationException
     */
    public function store(Seance $seance, string $enrolmentId, UploadedFile $file, ?string $userId): AttendanceProof
    {
        $type = AttendanceScans::detectedType($file) ?? throw ValidationException::withMessages([AttendanceScans::FIELD => 'Le justificatif doit être un fichier PDF ou une image (PNG, JPEG).']);
        $date = $seance->date->toDateString();
        $path = TenantStorage::path('attendance_proofs', $seance->training_session_id.'/'.$enrolmentId.'/'.Str::uuid7().'.'.$type);
        $disk = Storage::disk(TenantStorage::DISK);

        if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
            report(new RuntimeException('An absence proof could not be written to the private disk.'));

            throw ValidationException::withMessages([AttendanceScans::FIELD => "Le justificatif n'a pas pu être enregistré. Réessayez ou contactez l'administrateur."]);
        }

        try {
            return AttendanceProof::query()->create([
                'seance_id' => $seance->id,
                'enrolment_id' => $enrolmentId,
                'path' => $path,
                'original_name' => 'justificatif-'.$date.'.'.$type,
                'mime' => self::MIMES[$type],
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    public function delete(AttendanceProof $proof): void
    {
        DB::transaction(function () use ($proof): void {
            $proof->delete();
            $path = $proof->path;
            DB::afterCommit(fn () => Storage::disk(TenantStorage::DISK)->delete($path));
        });
    }

    /**
     * The proofs of a séance, by enrolment.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function forSeance(Seance $seance): array
    {
        return AttendanceProof::query()->where('seance_id', $seance->id)->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('enrolment_id')
            ->map(fn ($proofs) => $proofs->map(fn (AttendanceProof $p): array => [
                'id' => $p->id,
                'name' => $p->original_name,
                'size_bytes' => $p->size_bytes,
                'created_at' => $p->created_at?->toIso8601String(),
                'url' => route('seances.proofs.show', [$seance->id, $p->id]),
            ])->values()->all())
            ->all();
    }

    /**
     * Anonymisation: the proofs of the enrolments and their files are erased.
     *
     * @param  list<string>  $enrolmentIds
     */
    public static function purgeOfEnrolments(array $enrolmentIds): void
    {
        if ($enrolmentIds === []) {
            return;
        }
        $paths = DB::table('attendance_proofs')->whereIn('enrolment_id', $enrolmentIds)->pluck('path')->all();
        DB::table('attendance_proofs')->whereIn('enrolment_id', $enrolmentIds)->delete();
        DB::afterCommit(function () use ($paths): void {
            Storage::disk(TenantStorage::DISK)->delete($paths);
        });
    }
}
