<?php

namespace App\Modules\Crm\Import\Readers;

use App\Modules\Crm\Import\ImportFileException;
use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

/**
 * First sheet of an .xlsx workbook, every cell read as text: a number typed
 * in a cell comes back as its digits (73282932000074, never 7,33E+13), a
 * decimal keeps its decimals, a date becomes JJ/MM/AAAA (with HH:MM when it
 * has a time). Rows are numbered as in the spreadsheet; empty ones skipped.
 */
final class XlsxReader implements TabularReader
{
    public const UNREADABLE = 'Le fichier .xlsx est illisible (fichier endommagé ou d\'un autre format).';

    private Headers $headers;

    private int $headerLine = 1;

    private function __construct(private readonly string $path)
    {
        $cells = null;
        foreach ($this->records() as $line => $record) {
            if (array_filter($record, fn (string $c) => Cells::clean($c) !== '') !== []) {
                [$cells, $this->headerLine] = [$record, $line];
                break;
            }
        }

        if ($cells === null) {
            throw new ImportFileException('Le fichier est vide.');
        }

        $this->headers = new Headers($cells);
    }

    public static function open(string $path): self
    {
        $handle = @fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== "PK\x03\x04" || ! self::hasContentTypes($path)) {
            throw new ImportFileException(self::UNREADABLE);
        }

        return new self($path);
    }

    /**
     * An Office Open XML package always lists its parts in
     * [Content_Types].xml: a plain zip renamed .xlsx does not.
     */
    private static function hasContentTypes(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            return $zip->locateName('[Content_Types].xml') !== false;
        } finally {
            $zip->close();
        }
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
        foreach ($this->records() as $line => $record) {
            if ($line <= $this->headerLine) {
                continue;
            }
            $row = $this->headers->combine($record);
            if ($row !== null) {
                yield $line => $row;
            }
        }
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function records(): Generator
    {
        $options = new Options;
        // Keeps the iterator key equal to the spreadsheet row number.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);

        try {
            $reader->open($this->path);
        } catch (Throwable) {
            throw new ImportFileException(self::UNREADABLE);
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $line => $row) {
                    yield (int) $line => array_map(self::text(...), $row->getCells());
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    private static function text(Cell $cell): string
    {
        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_bool($value) => $value ? 'Oui' : 'Non',
            is_float($value) => self::decimal($value),
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('d/m/Y') : $value->format('d/m/Y H:i'),
            $value instanceof DateInterval => $value->format('%H:%I'),
        };
    }

    /**
     * Shortest exact text of a float (1234.5, 7.3282932000074E+13).
     */
    private static function decimal(float $value): string
    {
        $text = json_encode($value);

        return $text === false ? (string) $value : str_replace('e+', 'E+', $text);
    }
}
