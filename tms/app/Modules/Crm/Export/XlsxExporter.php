<?php

namespace App\Modules\Crm\Export;

use App\Modules\Core\Users\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a list as an .xlsx download (OpenSpout, constant memory).
 *
 * Cells are text unless the resource types them (ExportCell: amounts,
 * percentages, dates, with their French display format): a SIRET never
 * turns into 7,32829E+13. Text a spreadsheet could read as a formula is
 * neutralised (OWASP CSV injection): see neutralise().
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
            throw ValidationException::withMessages(['export' => self::tooManyRows($count, $max)]);
        }

        return $count;
    }

    /**
     * Rows the query would export, and the maximum allowed (pre-flight check).
     *
     * @param  Builder<Model>  $query
     * @return array{rows: int, max: int}
     */
    public function size(Builder $query): array
    {
        return ['rows' => (clone $query)->reorder()->count(), 'max' => (int) config('tms.crm.export_max_rows', self::MAX_ROWS)];
    }

    /**
     * @param  Builder<Model>  $query  already filtered and ordered
     * @param  list<string>  $columns  keys of $resource->columns(), validated by the caller
     */
    public function export(Builder $query, ExportResource $resource, User $user, array $columns, string $filename, ?int $rows = null): StreamedResponse
    {
        if ($rows === null) {
            $this->count($query);
        }
        $headers = array_map(fn (string $c) => self::neutralise($resource->columns()[$c]) ?? '', $columns);

        return response()->streamDownload(function () use ($query, $resource, $user, $columns, $headers): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName($resource->sheetName());
            $writer->addRow(Row::fromValues($headers, (new Style)->setFontBold()));

            $styles = [];
            foreach ((clone $query)->with($resource->with($user))->lazy(self::CHUNK) as $model) {
                $writer->addRow(new Row(array_map(
                    fn (string $c) => self::cell($resource->value($model, $c), $styles),
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
     * @param  array<string, Style>  $styles  one style per number format, reused
     */
    private static function cell(string|ExportCell|null $value, array &$styles): Cell
    {
        if (! $value instanceof ExportCell) {
            return Cell::fromValue(self::neutralise($value) ?? '');
        }
        $style = $styles[$value->format] ??= (new Style)->setFormat($value->format);

        return $value->value instanceof DateTimeInterface
            ? new DateTimeCell($value->value, $style)
            : new NumericCell($value->value, $style);
    }

    public static function tooManyRows(int $count, int $max): string
    {
        return 'Trop de lignes à exporter ('.number_format($count, 0, ',', ' ').', maximum '.number_format($max, 0, ',', ' ').'). Affinez les filtres.';
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

        // Spreadsheets also skip leading blanks and line feeds before a formula.
        $first = ltrim($value, " \t\r\n")[0] ?? '';

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) || in_array($first, ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
