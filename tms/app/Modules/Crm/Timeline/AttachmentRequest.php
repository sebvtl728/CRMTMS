<?php

namespace App\Modules\Crm\Timeline;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * An upload: size, extension and the real content type (finfo) must agree,
 * so that a script renamed .pdf is refused.
 */
class AttachmentRequest extends FormRequest
{
    public const TYPE_MESSAGE = "Ce type de fichier n'est pas accepté. Formats acceptés : PDF, JPG, PNG, WEBP, DOCX, XLSX, ODT, ODS, TXT, CSV.";

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:'.Attachment::MAX_KILOBYTES,
                'extensions:'.implode(',', array_keys(Attachment::TYPES)),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && self::detectedMime($value) === null) {
                        $fail(self::TYPE_MESSAGE);
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choisissez un fichier.',
            'file.file' => 'Le fichier envoyé est invalide.',
            'file.uploaded' => 'Le fichier n\'a pas pu être envoyé (taille maximale : 20 Mo).',
            'file.max' => 'Le fichier est trop volumineux (maximum : 20 Mo).',
            'file.extensions' => self::TYPE_MESSAGE,
        ];
    }

    /**
     * The MIME type to store when the content matches the extension, else null.
     */
    public static function detectedMime(UploadedFile $file): ?string
    {
        $allowed = Attachment::TYPES[strtolower($file->getClientOriginalExtension())] ?? null;
        $path = $file->getRealPath();

        if ($allowed === null || $path === false) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $real = $finfo->file($path);

        return is_string($real) && in_array($real, $allowed, true) ? $allowed[0] : null;
    }
}
