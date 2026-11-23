<?php

namespace App\Modules\Connectors\OpenData;

use App\Modules\Connectors\Core\HostPolicy;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * France compétences' open data export of the RNCP and RS (data.gouv.fr,
 * Licence Ouverte), published every night under a new URL: the dataset API
 * names the latest « export-fiches-csv-<date>.zip », which is downloaded to a
 * temporary file. Every host is checked by HostPolicy (no internal address).
 */
final class RncpExport
{
    /** API v2, paginated, newest first (the v1 answer lists all 8 000+ past files, 16 MB). */
    public const DATASET_API = 'https://www.data.gouv.fr/api/2/datasets/5eebbc067a14b6fecc9c9976/resources/?page=1&page_size=20';

    /** The export weighs about 10 MB: anything far bigger is refused. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /**
     * Downloads the latest CSV export; returns the path of a temporary zip
     * the caller deletes.
     *
     * @throws RuntimeException when the export cannot be found or fetched
     */
    public function download(): string
    {
        self::guard(self::DATASET_API);
        $dataset = Http::acceptJson()->timeout(30)->connectTimeout(10)->get(self::DATASET_API);
        if (! $dataset->successful()) {
            throw new RuntimeException('Jeu de données RNCP injoignable (HTTP '.$dataset->status().').');
        }
        $resources = $dataset->json('data');
        $latest = null;
        foreach (is_array($resources) ? $resources : [] as $resource) {
            $title = is_array($resource) ? (string) ($resource['title'] ?? '') : '';
            if (preg_match('/^export-fiches-csv-\d{4}-\d{2}-\d{2}\.zip$/', $title) === 1 && ($latest === null || $title > (string) $latest['title'])) {
                $latest = $resource;
            }
        }
        $url = is_array($latest) ? (string) ($latest['url'] ?? '') : '';
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Aucun export CSV du RNCP publié.');
        }

        self::guard($url);
        $path = tempnam(sys_get_temp_dir(), 'rncp-').'.zip';
        $response = Http::timeout(300)->connectTimeout(10)->sink($path)->get($url);
        if (! $response->successful() || ! is_file($path) || filesize($path) === 0 || filesize($path) > self::MAX_BYTES) {
            @unlink($path);
            throw new RuntimeException('Export RNCP non téléchargé (HTTP '.$response->status().').');
        }

        return $path;
    }

    private static function guard(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = HostPolicy::resolve($host);
        if ($ips === [] || HostPolicy::violation($ips, strict: true) !== null) {
            throw new RuntimeException("Adresse refusée pour l'export RNCP : {$host}.");
        }
    }
}
