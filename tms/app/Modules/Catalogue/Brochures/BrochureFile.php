<?php

namespace App\Modules\Catalogue\Brochures;

use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Validation of an uploaded brochure: a PDF of 10 Mo at most, checked by its
 * content (extension, MIME type read from the bytes and the « %PDF- »
 * signature), never by what the browser claims. A renamed executable or
 * script is refused with a French message before anything is written.
 */
final class BrochureFile
{
    public const MAX_KILOBYTES = 10240;

    public const FIELD = 'file';

    public const TYPE_MESSAGE = 'La plaquette doit être un fichier PDF.';

    public const EMPTY_MESSAGE = 'Le fichier est vide.';

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $required): array
    {
        return [self::FIELD => [
            'bail', $required ? 'required' : 'nullable', 'file', 'max:'.self::MAX_KILOBYTES,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                if ($value->getSize() === 0) {
                    $fail(self::EMPTY_MESSAGE);
                } elseif (! self::isPdf($value)) {
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
            self::FIELD.'.required' => 'Choisissez un fichier PDF.',
            self::FIELD.'.file' => 'Le fichier envoyé est invalide.',
            self::FIELD.'.uploaded' => 'La plaquette n\'a pas pu être envoyée (taille maximale : 10 Mo).',
            self::FIELD.'.max' => 'La plaquette ne doit pas dépasser 10 Mo.',
        ];
    }

    public static function isPdf(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false || strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return false;
        }

        return (new \finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf'
            && file_get_contents($path, false, null, 0, 5) === '%PDF-';
    }
}
