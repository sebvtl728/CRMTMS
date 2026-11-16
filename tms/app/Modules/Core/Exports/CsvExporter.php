<?php

namespace App\Modules\Core\Exports;

use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a list as a French CSV download: UTF-8 with a byte order mark (so
 * that Excel reads the accents), « ; » between fields, CRLF line ends, each
 * typed cell written as French text (ExportCell::display: « 1 234,50 »,
 * « 20/12/2026 »). Text a spreadsheet could read as a formula is
 * neutralised (XlsxExporter::neutralise). Same resources (ExportResource)
 * and row limit (XlsxExporter::count) as the Excel export; a resource that
 * PrefetchesRows reads what a chunk needs before its rows.
 */
final class CsvExporter
{
    private const CHUNK = 500;

    /**
     * @param  Builder<Model>  $query  already filtered, ordered and counted
     * @param  list<string>  $columns  keys of $resource->columns(), validated by the caller
     */
    public function export(Builder $query, ExportResource $resource, User $user, array $columns, string $filename): StreamedResponse
    {
        $headers = array_map(fn (string $c) => XlsxExporter::neutralise($resource->columns()[$c]) ?? '', $columns);

        return response()->streamDownload(function () use ($query, $resource, $user, $columns, $headers): void {
            $out = fopen('php://output', 'wb');
            assert($out !== false);
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';', '"', '', "\r\n");

            foreach ((clone $query)->with($resource->with($user))->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
                /** @var list<Model> $models */
                $models = $chunk->values()->all();
                if ($resource instanceof PrefetchesRows) {
                    $resource->prefetch($models);
                }
                foreach ($models as $model) {
                    fputcsv($out, array_map(
                        fn (string $c) => XlsxExporter::neutralise(ExportCell::display($resource->value($model, $c))) ?? '',
                        $columns,
                    ), ';', '"', '', "\r\n");
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
