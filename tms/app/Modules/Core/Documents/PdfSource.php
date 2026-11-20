<?php

namespace App\Modules\Core\Documents;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

/**
 * The exact HTML a PDF was rendered from (page and footer), archived as JSON
 * next to it (« <pdf>.source.json »): an online signature renders it again
 * with the drawn signature in the box marked by SLOT, so the signed document
 * carries the very text the signer read, without recomputing any data.
 */
final class PdfSource
{
    /** Where a template puts the signer's drawn signature (inside the signer's box). */
    public const SLOT = '<div class="signature-slot"></div>';

    public function __construct(public readonly string $html, public readonly ?string $footer) {}

    public static function pathFor(string $pdfPath): string
    {
        return $pdfPath.'.source.json';
    }

    /** Archives the source of the PDF stored at $pdfPath; returns its path. */
    public static function store(string $pdfPath, string $html, ?string $footer): string
    {
        $path = self::pathFor($pdfPath);
        $json = json_encode(['html' => $html, 'footer' => $footer], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! Storage::disk(TenantStorage::DISK)->put($path, $json)) {
            throw new RuntimeException('The PDF source could not be written to the private disk.');
        }

        return $path;
    }

    /** The source of an archived JSON content, null when unreadable. */
    public static function parse(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data) || ! is_string($data['html'] ?? null) || ! (is_string($data['footer'] ?? null) || ($data['footer'] ?? null) === null)) {
            return null;
        }

        return new self($data['html'], $data['footer']);
    }

    public function hasSlot(): bool
    {
        return str_contains($this->html, self::SLOT);
    }

    /** The page with $html in place of the slot (first one only). */
    public function filled(string $html): string
    {
        $at = strpos($this->html, self::SLOT);

        return $at === false ? $this->html : substr_replace($this->html, $html, $at, strlen(self::SLOT));
    }
}
