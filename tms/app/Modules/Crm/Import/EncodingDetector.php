<?php

namespace App\Modules\Crm\Import;

/**
 * Text encoding of a CSV export: UTF-8 (with or without byte order mark) or
 * Windows-1252 (Excel « CSV (séparateur : point-virgule) » on Windows).
 *
 * Robust rule: bytes that are valid UTF-8 are UTF-8 (Windows-1252 accented
 * text is practically never valid UTF-8); anything else is Windows-1252.
 */
final class EncodingDetector
{
    public const UTF8 = 'UTF-8';

    public const WINDOWS_1252 = 'Windows-1252';

    public const BOM = "\xEF\xBB\xBF";

    /**
     * @return 'UTF-8'|'Windows-1252'
     */
    public static function detect(string $sample): string
    {
        if (str_starts_with($sample, self::BOM)) {
            return self::UTF8;
        }

        return mb_check_encoding(self::withoutCutCharacter($sample), 'UTF-8') ? self::UTF8 : self::WINDOWS_1252;
    }

    /**
     * Whole file, read in 1 Mo chunks (never loaded at once).
     *
     * @return 'UTF-8'|'Windows-1252'
     */
    public static function detectFile(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ImportFileException('Le fichier est illisible.');
        }

        try {
            $carry = '';
            $first = true;
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    break;
                }
                if ($first && str_starts_with($chunk, self::BOM)) {
                    return self::UTF8;
                }
                $first = false;
                $data = $carry.$chunk;
                $complete = self::withoutCutCharacter($data);
                $carry = substr($data, strlen($complete));
                if (! mb_check_encoding($complete, 'UTF-8')) {
                    return self::WINDOWS_1252;
                }
            }

            return $carry === '' || mb_check_encoding($carry, 'UTF-8') ? self::UTF8 : self::WINDOWS_1252;
        } finally {
            fclose($handle);
        }
    }

    /**
     * $bytes without a UTF-8 sequence cut at its end (a sample or a chunk may
     * stop in the middle of a character).
     */
    private static function withoutCutCharacter(string $bytes): string
    {
        $length = strlen($bytes);

        // A sequence is at most 4 bytes: look for its lead byte in the last 3.
        for ($i = 1; $i <= min(3, $length); $i++) {
            $byte = ord($bytes[$length - $i]);
            if ($byte < 0x80) {
                return $bytes; // ASCII: nothing cut.
            }
            if ($byte >= 0xC0) {
                $expected = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $expected > $i ? substr($bytes, 0, $length - $i) : $bytes;
            }
        }

        return $bytes;
    }
}
