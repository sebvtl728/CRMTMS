<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Downloadable .xlsx report of a run: one line per file row (row number,
 * outcome, messages, fiche id). No value of the file is copied into it.
 */
final class ImportReport
{
    public const HEADERS = ['Ligne', 'Statut', 'Messages', 'Fiche', 'Identifiant de la fiche'];

    private const TYPES = ['organisation' => 'Entreprise', 'person' => 'Personne', 'affiliation' => 'Rattachement', 'note' => 'Note'];

    public static function write(ImportBatch $batch): string
    {
        $path = TenantStorage::path('imports', "{$batch->id}/rapport.xlsx");
        $disk = Storage::disk(TenantStorage::DISK);
        $disk->makeDirectory(dirname($path));

        $writer = new Writer;
        $writer->openToFile($disk->path($path));
        $writer->getCurrentSheet()->setName('Rapport');
        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

        foreach ($batch->rows()->orderBy('line')->lazy(RunImport::CHUNK) as $row) {
            $writer->addRow(Row::fromValues([
                (string) $row->line,
                ImportRow::LABELS[$row->status] ?? $row->status,
                XlsxExporter::neutralise(implode("\n", array_map(fn (array $m) => $m['text'], $row->messages))) ?? '',
                self::TYPES[$row->subject_type ?? ''] ?? '',
                (string) $row->subject_id,
            ]));
        }
        $writer->close();

        return $path;
    }
}
