<?php

namespace App\Modules\Crm\Import\Readers;

use App\Modules\Crm\Import\EncodingDetector;
use App\Modules\Crm\Import\ImportFileException;
use Generator;

/**
 * CSV / TSV reader. Delimiter (; , or tab) detected on the first 5 non-empty
 * lines, encoding detected on the whole file (UTF-8 with or without BOM, else
 * Windows-1252), both can be forced. Quoted fields may hold delimiters,
 * doubled quotes and line breaks. Fully empty lines are skipped (their row
 * number still counts, as in a spreadsheet).
 */
final class CsvReader implements TabularReader
{
    public const DELIMITERS = [';', "\t", ','];

    private Headers $headers;

    /** Row number of the header (empty lines before it are skipped). */
    private int $headerLine = 1;

    private function __construct(
        private readonly string $path,
        private readonly string $delimiter,
        private readonly string $encoding,
    ) {
        $handle = $this->handle();

        try {
            $line = 0;
            $cells = null;
            while (($record = $this->record($handle)) !== null) {
                $line++;
                if (! self::isEmpty($record)) {
                    $cells = $record;
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($cells === null) {
            throw new ImportFileException('Le fichier est vide.');
        }
        $this->headerLine = $line;
        $this->headers = new Headers($cells);
    }

    public static function open(string $path, ?string $delimiter = null, ?string $encoding = null): self
    {
        if (! is_readable($path)) {
            throw new ImportFileException('Le fichier est illisible.');
        }

        $encoding ??= EncodingDetector::detectFile($path);
        if (! in_array($encoding, [EncodingDetector::UTF8, EncodingDetector::WINDOWS_1252], true)) {
            throw new ImportFileException('Encodage non pris en charge (UTF-8 ou Windows-1252).');
        }

        return new self($path, $delimiter ?? self::detectDelimiter($path), $encoding);
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

    public function headers(): array
    {
        return $this->headers->names;
    }

    public function warnings(): array
    {
        return $this->headers->warnings;
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    public function rows(): Generator
    {
        $handle = $this->handle();

        try {
            $line = 0;
            while (($record = $this->record($handle)) !== null) {
                $line++;
                if ($line <= $this->headerLine) {
                    continue;
                }
                $row = $this->headers->combine($record);
                if ($row !== null) {
                    yield $line => $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The delimiter found the most consistently (highest minimum count per
     * line, outside quotes) on the first 5 non-empty lines; « ; » by default.
     */
    public static function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ImportFileException('Le fichier est illisible.');
        }

        $lines = [];
        try {
            while (count($lines) < 5 && ($line = fgets($handle)) !== false) {
                $line = str_starts_with($line, EncodingDetector::BOM) ? substr($line, 3) : $line;
                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }
        } finally {
            fclose($handle);
        }

        $best = ';';
        $bestScore = [0, 0];
        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(fn (string $l) => self::countOutsideQuotes($l, $delimiter), $lines);
            $score = $counts === [] ? [0, 0] : [min($counts), array_sum($counts)];
            if ($score > $bestScore) {
                [$best, $bestScore] = [$delimiter, $score];
            }
        }

        return $best;
    }

    private static function countOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $quoted = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $line[$i] === $delimiter) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return resource
     */
    private function handle()
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new ImportFileException('Le fichier est illisible.');
        }
        if (fread($handle, 3) !== EncodingDetector::BOM) {
            rewind($handle);
        }

        return $handle;
    }

    /**
     * Next record as UTF-8 cells, null at the end of the file.
     *
     * @param  resource  $handle
     * @return list<string>|null
     */
    private function record($handle): ?array
    {
        $cells = fgetcsv($handle, null, $this->delimiter, '"', '');
        if ($cells === false) {
            return null;
        }

        return array_map(function (?string $cell): string {
            $cell ??= '';

            // A forced UTF-8 on other bytes: invalid sequences replaced, never a crash.
            return $this->encoding === EncodingDetector::UTF8 ? mb_scrub($cell, 'UTF-8') : mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
        }, $cells);
    }

    /**
     * @param  list<string>  $cells
     */
    private static function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (Cells::clean($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
