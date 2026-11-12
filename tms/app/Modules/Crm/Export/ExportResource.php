<?php

namespace App\Modules\Crm\Export;

use Illuminate\Database\Eloquent\Model;

/**
 * What an Excel export of a resource offers: its columns (French headers),
 * the default selection, the relations to load, and each cell value.
 */
interface ExportResource
{
    /** Name used in the file (« entreprises » → entreprises-20261112-1530.xlsx). */
    public function fileName(): string;

    /** Title of the worksheet. */
    public function sheetName(): string;

    /**
     * @return array<string, string> column key => French header, in display order
     */
    public function columns(): array;

    /**
     * @return list<string>
     */
    public function defaults(): array;

    /**
     * @return list<string>
     */
    public function with(): array;

    public function value(Model $model, string $column): ?string;
}
