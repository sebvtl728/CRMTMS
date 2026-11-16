<?php

namespace App\Modules\Catalogue\Assistant;

/** The text read from a PDF, and whether it was cut to PdfText::MAX_CHARS. */
final class ExtractedText
{
    public function __construct(
        public readonly string $text,
        public readonly bool $truncated,
    ) {}
}
