<?php

namespace App\Modules\Catalogue\Assistant;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The text of a training programme (PDF), read on the server with
 * pdftotext (poppler-utils, in the application image): the AI receives
 * this text only, never the file.
 *
 *  - rules(): a PDF of 10 Mo at most, checked by its content (extension,
 *    MIME type read from the bytes, « %PDF- » signature);
 *  - extract(): the cleaned text, cut to MAX_CHARS; the uploaded file is
 *    deleted afterwards, whatever happens (nothing is kept). A PDF without
 *    text (scan) or unreadable is refused with a French message on « file ».
 */
final class PdfText
{
    /** Largest text sent to the AI (about 15 000 tokens): a long programme is cut, and the screen says so. */
    public const MAX_CHARS = 60000;

    public const MAX_KILOBYTES = 10240;

    /** Pages read at most (a 10 Mo programme rarely has more). */
    public const MAX_PAGES = 300;

    public const TIMEOUT_SECONDS = 30;

    public const FIELD = 'file';

    public const TYPE_MESSAGE = 'Le programme doit être un fichier PDF.';

    public const NO_TEXT = 'Ce PDF ne contient pas de texte lisible (document scanné ?). Utilisez plutôt « Depuis une demande ».';

    public const UNREADABLE = 'Ce PDF n\'a pas pu être lu (fichier endommagé ou protégé).';

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
                    $fail('Le fichier est vide.');
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
            self::FIELD.'.file' => 'Choisissez un fichier PDF.',
            self::FIELD.'.uploaded' => 'Le programme n\'a pas pu être envoyé (taille maximale : 10 Mo).',
            self::FIELD.'.max' => 'Le programme ne doit pas dépasser 10 Mo.',
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

    /**
     * @throws ValidationException
     */
    public function extract(UploadedFile $file): ExtractedText
    {
        $path = $file->getRealPath();

        try {
            if ($path === false) {
                throw self::refused(self::UNREADABLE);
            }

            try {
                $result = Process::timeout(self::TIMEOUT_SECONDS)
                    ->run(['pdftotext', '-q', '-enc', 'UTF-8', '-nopgbrk', '-l', (string) self::MAX_PAGES, $path, '-']);
            } catch (Throwable) {
                throw self::refused(self::UNREADABLE);
            }
            if (! $result->successful()) {
                throw self::refused(self::UNREADABLE);
            }

            $text = self::clean($result->output());
        } finally {
            if ($path !== false && is_file($path)) {
                @unlink($path);
            }
        }

        if (preg_match('/\p{L}{2,}/u', $text) !== 1) {
            throw self::refused(self::NO_TEXT);
        }

        $truncated = mb_strlen($text) > self::MAX_CHARS;

        return new ExtractedText($truncated ? mb_substr($text, 0, self::MAX_CHARS) : $text, $truncated);
    }

    /** Valid UTF-8, no control characters, blank runs folded. */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */', "\n", $text) ?? '';
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';

        return trim($text);
    }

    private static function refused(string $message): ValidationException
    {
        return ValidationException::withMessages([self::FIELD => $message]);
    }
}
