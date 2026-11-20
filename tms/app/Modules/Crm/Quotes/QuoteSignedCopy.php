<?php

namespace App\Modules\Crm\Quotes;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\StagedFile;
use App\Modules\Core\Tenancy\TenantStorage;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The signed copy of an accepted quote, sent back by the customer: one file
 * per quote on the private disk, tenants/<tenant_id>/quotes/<quote_id>/signe.<pdf|jpg|png>.
 * Only a PDF, a JPEG or a PNG whose content (signature, decodable image)
 * matches its extension is accepted, never an SVG nor a renamed file.
 * Journaled as quote.signed_added (type, size, SHA-256: never the name nor
 * the content); the original name is personal (Quote::$auditPersonal).
 */
final class QuoteSignedCopy
{
    public const MAX_KILOBYTES = 10240;

    public const FIELD = 'signed';

    public const TYPE_MESSAGE = 'Le devis signé doit être un PDF, une image JPEG ou PNG.';

    public const NOT_ACCEPTED = 'Seul un devis accepté peut recevoir son exemplaire signé.';

    /** extension => [stored extension, MIME type] */
    private const TYPES = [
        'pdf' => ['pdf', 'application/pdf'],
        'png' => ['png', 'image/png'],
        'jpg' => ['jpg', 'image/jpeg'],
        'jpeg' => ['jpg', 'image/jpeg'],
    ];

    /** stored extension => MIME type served */
    private const SERVED = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Rules of the file field: optional on the « Accepté » window, required
     * on « Joindre le devis signé ».
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $required): array
    {
        return [self::FIELD => [
            $required ? 'required' : 'nullable', 'file', 'max:'.self::MAX_KILOBYTES,
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof UploadedFile && self::detectedType($value) === null) {
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
            self::FIELD.'.required' => 'Choisissez un fichier.',
            self::FIELD.'.file' => 'Le fichier envoyé est invalide.',
            self::FIELD.'.uploaded' => 'Le devis signé n\'a pas pu être envoyé (taille maximale : 10 Mo).',
            self::FIELD.'.max' => 'Le devis signé ne doit pas dépasser 10 Mo.',
        ];
    }

    /**
     * The extension to store ('pdf', 'png' or 'jpg') when the file really is
     * what its name claims, else null.
     */
    public static function detectedType(UploadedFile $file): ?string
    {
        $type = self::TYPES[strtolower($file->getClientOriginalExtension())] ?? null;
        $path = $file->getRealPath();
        if ($type === null || $path === false) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== $type[1]) {
            return null;
        }
        if ($type[0] === 'pdf') {
            return file_get_contents($path, false, null, 0, 5) === '%PDF-' ? 'pdf' : null;
        }
        $image = @getimagesize($path);
        $expected = $type[0] === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        return $image !== false && $image[2] === $expected ? $type[0] : null;
    }

    /**
     * Stores (or replaces) the signed copy of an accepted quote: new file
     * under a temporary name, then the columns and the journal; once
     * committed, the file takes its name and a previous one of another name
     * goes (StagedFile). A failed save leaves the previous copy intact.
     *
     * @throws UserFacingHttpException 409 when the quote is not accepted
     * @throws ValidationException when the file is not accepted or cannot be written
     */
    public function store(Quote $quote, UploadedFile $file): void
    {
        $extension = self::detectedType($file) ?? throw ValidationException::withMessages([self::FIELD => self::TYPE_MESSAGE]);
        if ($quote->status !== QuoteStatus::Accepted) {
            throw new UserFacingHttpException(409, self::NOT_ACCEPTED);
        }

        $directory = TenantStorage::path('quotes', $quote->id);
        $path = $directory.'/signe.'.$extension;

        // Temporary name first: a previous copy stays intact until the save is committed.
        $staged = StagedFile::stage($directory, $file, $extension);
        if ($staged === null) {
            report(new RuntimeException('Signed quote could not be written to the private disk.'));

            throw ValidationException::withMessages([self::FIELD => "Le devis signé n'a pas pu être enregistré. Réessayez ou contactez l'administrateur."]);
        }

        try {
            DB::transaction(function () use ($quote, $file, $path, $extension, $staged): void {
                $locked = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());
                if ($locked->status !== QuoteStatus::Accepted) {
                    throw new UserFacingHttpException(409, self::NOT_ACCEPTED);
                }
                $previous = $locked->signed_path;
                $locked->forceFill([
                    'signed_path' => $path,
                    'signed_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'signed_at' => now(),
                ])->save();
                $this->audit->record('quote.signed_added', $locked, [], [
                    'type' => $extension,
                    'size' => $file->getSize(),
                    'sha256' => hash_file('sha256', (string) $file->getRealPath()),
                ]);
                $quote->setRawAttributes($locked->getAttributes(), true);
                StagedFile::promote($staged, $path, $previous !== null && self::isOwnPath($quote, $previous) ? $previous : null);
            });
        } catch (\Throwable $e) {
            StagedFile::discard($staged);

            throw $e;
        }
    }

    /**
     * The PDF signed online (Core\Signatures) stored as the signed copy of an
     * accepted quote, in the caller's transaction.
     */
    public function storePdf(Quote $quote, string $content): void
    {
        $path = TenantStorage::path('quotes', $quote->id).'/signe-en-ligne.pdf';
        Storage::disk(TenantStorage::DISK)->put($path, $content);
        $previous = $quote->signed_path;
        $quote->forceFill(['signed_path' => $path, 'signed_name' => $quote->number.'-signe.pdf', 'signed_at' => now()])->save();
        $this->audit->record('quote.signed_added', $quote, [], ['type' => 'pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content), 'online' => true]);
        if ($previous !== null && $previous !== $path && self::isOwnPath($quote, $previous)) {
            DB::afterCommit(fn () => Storage::disk(TenantStorage::DISK)->delete($previous));
        }
    }

    /**
     * The stored copy (path and MIME type), null without one or when the file
     * is missing.
     *
     * @return array{path: string, mime: string, extension: string}|null
     */
    public static function file(Quote $quote): ?array
    {
        $path = $quote->signed_path;
        if ($path === null || ! self::isOwnPath($quote, $path) || ! Storage::disk(TenantStorage::DISK)->exists($path)) {
            return null;
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime = self::SERVED[$extension] ?? null;

        return $mime === null ? null : ['path' => $path, 'mime' => $mime, 'extension' => $extension];
    }

    /**
     * Only files in this quote's own directory are ever read or deleted.
     */
    public static function isOwnPath(Quote $quote, string $path): bool
    {
        return str_starts_with($path, TenantStorage::path('quotes', $quote->id).'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }
}
