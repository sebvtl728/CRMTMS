<?php

namespace App\Modules\Crm\Import\Readers;

/**
 * A table read from an uploaded file, streamed (never loaded at once).
 */
interface TabularReader
{
    /**
     * Column names, in file order (duplicates removed, empty ones named
     * « Colonne N »).
     *
     * @return list<string>
     */
    public function headers(): array;

    /**
     * Non-empty data rows keyed by their row number in the file (the header
     * is row 1, as in a spreadsheet), each as column => text value (trimmed,
     * '' when empty). Can be iterated several times.
     *
     * @return iterable<int, array<string, string>>
     */
    public function rows(): iterable;

    /**
     * French warnings about the file itself (duplicate columns...).
     *
     * @return list<string>
     */
    public function warnings(): array;
}
