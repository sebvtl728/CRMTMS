<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\CustomFields\CustomFieldValues;
use App\Modules\Core\CustomFields\CustomFieldDefinition;
use App\Modules\Core\CustomFields\CustomFieldType;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Files of a candidature, on the private disk under the « admissions » zone
 * of the tenant (TenantStorage). Kinds: « positioning » (several), « contract »
 * (the signed alternance contract, one) and « custom:<id> » (the value of a
 * visible « file » custom field, one). A PDF, JPG or PNG checked by its
 * content, 20 Mo at most. Each change touches the candidature (its last
 * change counts for the two-year anonymisation).
 */
final class CandidatureFiles
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** extension => [stored extension, MIME type] */
    private const TYPES = ['pdf' => ['pdf', 'application/pdf'], 'jpg' => ['jpg', 'image/jpeg'], 'jpeg' => ['jpg', 'image/jpeg'], 'png' => ['png', 'image/png']];

    public const SERVED = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    /** Kinds holding one file only (a new one replaces it). */
    private const SINGLE = ['contract'];

    public function store(Candidature $candidature, string $kind, UploadedFile $file, ?User $by): CandidatureFile
    {
        self::checkKind($kind);
        if (($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Le fichier dépasse 20 Mo.']);
        }
        $extension = self::detectedType($file) ?? throw ValidationException::withMessages(['file' => 'Formats acceptés : PDF, JPG, PNG.']);

        $disk = Storage::disk(TenantStorage::DISK);
        $path = TenantStorage::path('admissions', $candidature->id.'/'.Str::uuid7().'.'.$extension);
        $stream = fopen((string) $file->getRealPath(), 'rb');
        if ($stream === false || ! $disk->writeStream($path, $stream)) {
            report(new RuntimeException('Candidature file could not be written to the private disk.'));

            throw ValidationException::withMessages(['file' => 'Le fichier n\'a pas pu être enregistré. Réessayez ou contactez l\'administrateur.']);
        }

        try {
            $replaced = [];
            $stored = DB::transaction(function () use ($candidature, $kind, $file, $path, $by, &$replaced): CandidatureFile {
                Candidature::query()->lockForUpdate()->findOrFail($candidature->getKey());
                if (in_array($kind, self::SINGLE, true) || str_starts_with($kind, 'custom:')) {
                    $previous = CandidatureFile::query()->where('candidature_id', $candidature->id)->where('kind', $kind)->get();
                    foreach ($previous as $old) {
                        $replaced[] = $old->path;
                        $old->delete();
                    }
                }
                $stored = CandidatureFile::query()->create([
                    'candidature_id' => $candidature->id,
                    'kind' => $kind,
                    'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'size' => (int) $file->getSize(),
                    'path' => $path,
                    'uploaded_by' => $by?->id, // null: deposited by the candidate (positioning link)
                ]);
                $candidature->touch();

                return $stored;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
        foreach ($replaced as $old) {
            self::deleteFromDisk($candidature, $old);
        }

        return $stored;
    }

    public function delete(CandidatureFile $file): void
    {
        $candidature = $file->candidature;
        DB::transaction(function () use ($file, $candidature): void {
            $file->delete();
            $candidature?->touch();
        });
        if ($candidature !== null) {
            self::deleteFromDisk($candidature, $file->path);
        }
    }

    /** Every file of $candidature, rows and disk (anonymisation). */
    public function purge(Candidature $candidature): void
    {
        $paths = CandidatureFile::query()->where('candidature_id', $candidature->id)->pluck('path')->all();
        CandidatureFile::query()->where('candidature_id', $candidature->id)->get()->each->delete();
        foreach ($paths as $path) {
            self::deleteFromDisk($candidature, $path);
        }
    }

    public static function isOwnPath(Candidature $candidature, string $path): bool
    {
        return str_starts_with($path, TenantStorage::path('admissions', $candidature->id).'/') && ! str_contains($path, '..');
    }

    private static function deleteFromDisk(Candidature $candidature, string $path): void
    {
        if (self::isOwnPath($candidature, $path)) {
            Storage::disk(TenantStorage::DISK)->delete($path);
        }
    }

    private static function checkKind(string $kind): void
    {
        if (in_array($kind, ['positioning', 'contract'], true)) {
            return;
        }
        if (str_starts_with($kind, 'custom:')) {
            $id = substr($kind, 7);
            $field = Str::isUuid($id) ? CustomFieldDefinition::query()->where('entity', CustomFieldValues::ENTITY)->whereKey($id)->whereNull('hidden_at')->first() : null;
            if ($field !== null && $field->type === CustomFieldType::File) {
                return;
            }
        }

        throw ValidationException::withMessages(['kind' => 'Type de pièce inconnu.']);
    }

    /**
     * The extension to store when the file really is what its name claims.
     */
    public static function detectedType(UploadedFile $file): ?string
    {
        $type = self::TYPES[strtolower($file->getClientOriginalExtension())] ?? null;
        $path = $file->getRealPath();
        if ($type === null || $path === false) {
            return null;
        }
        if ((new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $type[1]) {
            return null;
        }
        if ($type[0] === 'pdf') {
            return file_get_contents($path, false, null, 0, 5) === '%PDF-' ? 'pdf' : null;
        }
        $image = @getimagesize($path);
        $expected = $type[0] === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        return $image !== false && $image[2] === $expected ? $type[0] : null;
    }
}
