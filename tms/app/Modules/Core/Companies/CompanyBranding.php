<?php

namespace App\Modules\Core\Companies;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\StagedFile;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The logo a company prints on its quotes. One file per company on the
 * private disk, tenants/<tenant_id>/companies/<company_id>/logo.<png|jpg>;
 * only PNG and JPEG whose content (signature and decodable image) matches the
 * extension are accepted, never SVG (scriptable). The PDF embeds it as a data
 * URI (logoDataUri), so rendering needs no network access.
 */
class CompanyBranding
{
    public const MAX_KILOBYTES = 1024;

    public const TYPE_MESSAGE = 'Le logo doit être une image PNG ou JPEG.';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** extension => [stored extension, MIME type, IMAGETYPE_*] */
    private const TYPES = [
        'png' => ['png', 'image/png', IMAGETYPE_PNG],
        'jpg' => ['jpg', 'image/jpeg', IMAGETYPE_JPEG],
        'jpeg' => ['jpg', 'image/jpeg', IMAGETYPE_JPEG],
    ];

    /**
     * The extension to store ('png' or 'jpg') when the file really is the
     * image its name claims, else null.
     */
    public static function detectedType(UploadedFile $file): ?string
    {
        $type = self::TYPES[strtolower($file->getClientOriginalExtension())] ?? null;
        $path = $file->getRealPath();
        if ($type === null || $path === false) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $image = @getimagesize($path);

        return $mime === $type[1] && $image !== false && $image[2] === $type[2] ? $type[0] : null;
    }

    /**
     * Replaces the logo: new file under a temporary name, then the column and
     * the journal (type, size and SHA-256 of the file, never its content);
     * once committed, the file takes its name and a previous file of another
     * name is deleted (StagedFile). A failed save leaves the old logo intact.
     *
     * @throws ValidationException when the file is not an accepted image or cannot be written
     */
    public function storeLogo(Company $company, UploadedFile $file): void
    {
        $extension = self::detectedType($file) ?? throw ValidationException::withMessages(['logo' => self::TYPE_MESSAGE]);

        $directory = $this->directory($company);
        $path = $directory.'/logo.'.$extension;
        $previous = $company->logo_path;

        // The new file under a temporary name: the current logo stays intact until the save is committed.
        $staged = StagedFile::stage($directory, $file, $extension);
        if ($staged === null) {
            report(new RuntimeException('Company logo could not be written to the private disk.'));

            throw ValidationException::withMessages(['logo' => "Le logo n'a pas pu être enregistré. Réessayez ou contactez l'administrateur."]);
        }

        try {
            DB::transaction(function () use ($company, $path, $previous, $extension, $file, $staged): void {
                $company->logo_path = $path;
                $company->save();
                $this->audit->record('company.logo_changed', $company, ['logo' => $previous === null ? null : pathinfo($previous, PATHINFO_BASENAME)], [
                    'logo' => 'logo.'.$extension,
                    'size' => $file->getSize(),
                    'sha256' => hash_file('sha256', (string) $file->getRealPath()),
                ]);
                // The brand (sidebar) is recomputed once the new file is in place.
                StagedFile::promote($staged, $path, $previous !== null && $this->isOwnPath($company, $previous) ? $previous : null, fn () => Brand::forget($company->tenant_id));
            });
        } catch (\Throwable $e) {
            StagedFile::discard($staged);

            throw $e;
        }
    }

    public function deleteLogo(Company $company): void
    {
        $previous = $company->logo_path;
        if ($previous === null) {
            return;
        }

        DB::transaction(function () use ($company, $previous): void {
            $company->logo_path = null;
            $company->save();
            $this->audit->record('company.logo_deleted', $company, ['logo' => pathinfo($previous, PATHINFO_BASENAME)]);
        });

        if ($this->isOwnPath($company, $previous)) {
            Storage::disk(TenantStorage::DISK)->delete($previous);
        }
    }

    /**
     * data:image/png;base64,… (or image/jpeg) for the PDF; null without a
     * logo, when the file is missing or no longer an accepted image.
     */
    public function logoDataUri(Company $company): ?string
    {
        $logo = $this->logoFile($company);

        return $logo === null ? null : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['content']);
    }

    /**
     * The stored logo (content, MIME type, SHA-256), for the PDF and the
     * application's brand (BrandLogoController); null without a logo, when
     * the file is missing or no longer an accepted image.
     *
     * @return array{content: string, mime: string, sha256: string}|null
     */
    public function logoFile(Company $company): ?array
    {
        $path = $company->logo_path;
        if ($path === null || ! $this->isOwnPath($company, $path)) {
            return null;
        }

        $bytes = Storage::disk(TenantStorage::DISK)->get($path);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return ['content' => $bytes, 'mime' => $mime, 'sha256' => hash('sha256', $bytes)];
    }

    private function directory(Company $company): string
    {
        return TenantStorage::path('companies', $company->id);
    }

    /**
     * Only files in this company's own directory are ever read or deleted.
     */
    private function isOwnPath(Company $company, string $path): bool
    {
        return str_starts_with($path, $this->directory($company).'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }
}
