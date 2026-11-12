<?php

namespace App\Modules\Crm\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a list as an .xlsx download (OpenSpout, constant memory).
 *
 * Every cell is written as text, so a SIRET never turns into 7,32829E+13.
 * Text a spreadsheet could read as a formula is neutralised (OWASP CSV
 * injection): see neutralise().
 */
final class XlsxExporter
{
    /** Above this many rows the export is refused (config tms.crm.export_max_rows). */
    public const MAX_ROWS = 50000;

    private const CHUNK = 500;

    /**
     * Number of rows the query would export; refuses an export that is too big.
     *
     * @param  Builder<Model>  $query
     *
     * @throws ValidationException
     */
    public function count(Builder $query): int
    {
        $count = (clone $query)->reorder()->count();
        $max = (int) config('tms.crm.export_max_rows', self::MAX_ROWS);

        if ($count > $max) {
            throw ValidationException::withMessages([
                'export' => 'Trop de lignes à exporter ('.number_format($count, 0, ',', ' ').', maximum '.number_format($max, 0, ',', ' ').'). Affinez les filtres.',
            ]);
        }

        return $count;
    }

    /**
     * @param  Builder<Model>  $query  already filtered and ordered
     * @param  list<string>  $columns  keys of $resource->columns(), validated by the caller
     */
    public function export(Builder $query, ExportResource $resource, array $columns, string $filename, ?int $rows = null): StreamedResponse
    {
        $rows ?? $this->count($query);
        $headers = array_map(fn (string $c) => self::neutralise($resource->columns()[$c]) ?? '', $columns);

        return response()->streamDownload(function () use ($query, $resource, $columns, $headers): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName($resource->sheetName());
            $writer->addRow(Row::fromValues($headers, (new Style)->setFontBold()));

            foreach ((clone $query)->with($resource->with())->lazy(self::CHUNK) as $model) {
                $writer->addRow(Row::fromValues(array_map(
                    fn (string $c) => self::neutralise($resource->value($model, $c)) ?? '',
                    $columns,
                )));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * A value starting with = + - @ tab or CR could run as a formula when the
     * file is opened: it gets a leading apostrophe.
     */
    public static function neutralise(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
