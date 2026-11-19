<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The proofs of a Qualiopi indicator: a file (checked by its content, 20 MB
 * at most, stored on the private disk under the organisme's « quality »
 * zone, named by uuid), a link (http or https only) or a note.
 */
final class QualityProofs
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const FORMATS = 'Formats acceptés : PDF, images (JPG, PNG), Word, Excel, PowerPoint, LibreOffice.';

    /** detected mime => stored extension */
    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
    ];

    public const SERVED = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    /**
     * @param  array<string, mixed>  $data  kind, title, url, note, file
     */
    public function add(int $number, array $data): QualityProof
    {
        $values = Validator::make($data, [
            'kind' => ['required', 'in:file,link,note'],
            'title' => ['required', 'string', 'max:200'],
            'url' => ['exclude_unless:kind,link', 'required', 'string', 'max:2000'],
            'note' => ['exclude_unless:kind,note', 'required', 'string', 'max:10000'],
            'file' => ['exclude_unless:kind,file', 'required', 'file'],
        ], [
            'title.required' => 'Donnez un titre à la preuve.',
            'url.required' => 'Indiquez l’adresse.',
            'note.required' => 'Écrivez la note.',
            'file.required' => 'Choisissez un fichier.',
            'max' => 'Ce texte est trop long.',
            'in' => 'Type de preuve inconnu.',
        ])->validate();

        $base = ['referential_version' => QualiopiReferential::VERSION, 'number' => $number, 'kind' => $values['kind'], 'title' => trim($values['title'])];
        if ($values['kind'] === 'link') {
            $url = trim((string) $values['url']);
            if (preg_match('#^https?://[^\s]+$#i', $url) !== 1) {
                throw ValidationException::withMessages(['url' => 'Indiquez une adresse web qui commence par http:// ou https://.']);
            }

            return QualityProof::query()->create([...$base, 'url' => $url]);
        }
        if ($values['kind'] === 'note') {
            return QualityProof::query()->create([...$base, 'note' => trim((string) $values['note'])]);
        }

        /** @var UploadedFile $file */
        $file = $values['file'];
        if (($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Le fichier dépasse 20 Mo.']);
        }
        $extension = self::detected($file) ?? throw ValidationException::withMessages(['file' => self::FORMATS]);
        $disk = Storage::disk(TenantStorage::DISK);
        $path = TenantStorage::path('quality', QualiopiReferential::VERSION.'/'.$number.'/'.Str::uuid7().'.'.$extension);
        $stream = fopen((string) $file->getRealPath(), 'rb');
        if ($stream === false || ! $disk->writeStream($path, $stream)) {
            report(new RuntimeException('Quality proof could not be written to the private disk.'));

            throw ValidationException::withMessages(['file' => 'Le fichier n’a pas pu être enregistré. Réessayez.']);
        }
        try {
            return DB::transaction(fn () => QualityProof::query()->create([
                ...$base,
                'path' => $path,
                'original_name' => mb_substr(mb_scrub($file->getClientOriginalName()), 0, 255),
                'size' => (int) $file->getSize(),
            ]));
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    public function delete(QualityProof $proof): void
    {
        $path = $proof->path;
        $proof->delete();
        if ($path !== null && self::isOwnPath($path)) {
            Storage::disk(TenantStorage::DISK)->delete($path);
        }
    }

    public static function isOwnPath(string $path): bool
    {
        return str_starts_with($path, TenantStorage::path('quality').'/') && ! str_contains($path, '..') && ! str_contains($path, "\0");
    }

    /** The stored extension of an accepted file, by its content (null: refused). */
    private static function detected(UploadedFile $file): ?string
    {
        $real = $file->getRealPath();
        if ($real === false) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($real);

        return self::TYPES[$mime] ?? null;
    }
}
