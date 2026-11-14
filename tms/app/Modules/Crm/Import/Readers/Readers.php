<?php

namespace App\Modules\Crm\Import\Readers;

use App\Modules\Crm\Import\ImportFileException;

/**
 * Opens an uploaded file with the reader of its format (from the original
 * file name): .csv / .txt / .tsv or .xlsx.
 */
final class Readers
{
    public const EXTENSIONS = ['csv', 'txt', 'tsv', 'xlsx'];

    /**
     * The real type of an uploaded file matches its extension (checked once,
     * at upload): a .csv is text, a .xlsx an Office Open XML package
     * (XlsxReader::open).
     *
     * @throws ImportFileException with a French message
     */
    public static function assertContent(string $path, string $originalName): void
    {
        if (in_array(mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), ['csv', 'txt', 'tsv'], true)) {
            CsvReader::assertText($path);
        }
    }

    /**
     * @param  array{delimiter?: string|null, encoding?: string|null}  $options  forced CSV settings
     */
    public static function open(string $path, string $originalName, array $options = []): TabularReader
    {
        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt', 'tsv' => CsvReader::open($path, $options['delimiter'] ?? null, $options['encoding'] ?? null),
            'xlsx' => XlsxReader::open($path),
            'xls' => throw new ImportFileException('Format .xls non pris en charge : enregistrez le fichier au format .xlsx ou .csv depuis Excel.'),
            default => throw new ImportFileException('Format de fichier non pris en charge : importez un fichier .csv ou .xlsx.'),
        };
    }
}
