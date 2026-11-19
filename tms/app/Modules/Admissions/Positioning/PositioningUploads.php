<?php

namespace App\Modules\Admissions\Positioning;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFiles;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Core\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The copy a candidate deposits through the personal link: 1 to MAX_FILES
 * files (PDF, JPG, PNG checked by their content, 20 MB each) stored as
 * positioning files of the candidature, once per link. Fills « Reçu le »
 * when empty and moves the stage to « Test reçu » from an earlier stage of
 * the path only (never back, never out of an exit).
 */
final class PositioningUploads
{
    public const MAX_FILES = 5;

    public function __construct(private readonly CandidatureFiles $files, private readonly AuditRecorder $audit) {}

    /**
     * The sending of a working link, its candidature live and on the path;
     * null for anything else (the same answer for every invalid link).
     */
    public static function usable(string $token, bool $allowSubmitted = false): ?PositioningSending
    {
        $sending = PositioningSending::findByToken($token);
        if ($sending === null || $sending->revoked_at !== null || ! $sending->expires_at->isFuture()) {
            return null;
        }
        if ($sending->submitted_at !== null && ! $allowSubmitted) {
            return null;
        }
        $candidature = Candidature::query()->find($sending->candidature_id);

        return $candidature === null || $candidature->status->isExit() ? null : $sending;
    }

    /**
     * @param  array<string, mixed>  $data  files (list of UploadedFile), done
     */
    public function submit(PositioningSending $sending, array $data): void
    {
        Validator::make($data, [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file'],
            'done' => ['accepted'],
        ], [
            'files.required' => 'Joignez votre copie (PDF, JPG ou PNG).',
            'files.min' => 'Joignez votre copie (PDF, JPG ou PNG).',
            'files.max' => 'Cinq fichiers au plus.',
            'files.*.file' => 'Fichier invalide.',
            'done.accepted' => 'Cochez la case pour confirmer que votre test est terminé.',
        ])->validate();
        /** @var list<UploadedFile> $uploads */
        $uploads = array_values($data['files']);
        foreach ($uploads as $i => $file) {
            if (($file->getSize() ?: 0) > CandidatureFiles::MAX_BYTES) {
                throw ValidationException::withMessages(["files.{$i}" => 'Le fichier dépasse 20 Mo.']);
            }
            if (CandidatureFiles::detectedType($file) === null) {
                throw ValidationException::withMessages(["files.{$i}" => 'Formats acceptés : PDF, JPG, PNG.']);
            }
        }

        DB::transaction(function () use ($sending, $uploads): void {
            /** @var PositioningSending|null $locked */
            $locked = PositioningSending::query()->lockForUpdate()->find($sending->id);
            if ($locked === null || ! $locked->linkActive()) {
                abort(404);
            }
            /** @var Candidature $candidature */
            $candidature = Candidature::query()->lockForUpdate()->findOrFail($locked->candidature_id);
            if ($candidature->status->isExit()) {
                abort(404);
            }
            foreach ($uploads as $file) {
                $this->files->store($candidature, 'positioning', $file, null);
            }
            $now = CarbonImmutable::now();
            PositioningSending::query()->whereKey($locked->id)->toBase()->update(['submitted_at' => $now, 'updated_at' => $now]);

            $candidature->refresh();
            $changes = [];
            if ($candidature->positioning_received_on === null) {
                $changes['positioning_received_on'] = CarbonImmutable::now('Europe/Paris')->toDateString();
            }
            $rank = $candidature->status->rank();
            if ($rank !== null && $rank < (int) CandidatureStatus::PositioningReceived->rank()) {
                $changes['status'] = CandidatureStatus::PositioningReceived->value;
            }
            if ($changes !== []) {
                $candidature->forceFill($changes)->save();
            }
            $this->audit->record('candidature.positioning_submitted', $candidature, [], ['files' => count($uploads)]);
        });
    }
}
