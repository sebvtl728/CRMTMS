<?php

namespace App\Modules\Catalogue\Rncp;

use App\Modules\Connectors\OpenData\RncpExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Replaces rncp_certifications with the RNCP fiches of the latest open data
 * export: code, title, level, state, end of registration, NSF codes and
 * blocks of competences (RS fiches left out). One transaction: readers see
 * the old table or the new one, never half of it.
 */
final class RncpSync
{
    public function __construct(private readonly RncpExport $export) {}

    /** @return int certifications loaded */
    public function run(): int
    {
        $zipPath = $this->export->download();
        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Export RNCP illisible.');
            }
            $fiches = $this->standard($zip);
            foreach ($this->rows($zip, 'Nsf') as $row) {
                $code = mb_strtoupper(trim($row['Numero_Fiche'] ?? ''));
                $nsf = trim($row['Nsf_Code'] ?? '');
                if (isset($fiches[$code]) && $nsf !== '' && ! in_array($nsf, $fiches[$code]['nsf_codes'], true)) {
                    $fiches[$code]['nsf_codes'][] = $nsf;
                }
            }
            foreach ($this->rows($zip, 'Blocs') as $row) {
                $code = mb_strtoupper(trim($row['Numero_Fiche'] ?? ''));
                $label = trim($row['Bloc_Competences_Libelle'] ?? '');
                if (isset($fiches[$code]) && $label !== '') {
                    $fiches[$code]['blocks'][] = ['code' => trim($row['Bloc_Competences_Code'] ?? ''), 'label' => $label];
                }
            }
            $zip->close();
        } finally {
            @unlink($zipPath);
        }
        if ($fiches === []) {
            throw new RuntimeException('Export RNCP vide : la table est gardée telle quelle.');
        }

        $now = CarbonImmutable::now();
        DB::transaction(function () use ($fiches, $now): void {
            DB::table('rncp_certifications')->delete();
            foreach (array_chunk(array_values($fiches), 500) as $chunk) {
                DB::table('rncp_certifications')->insert(array_map(fn (array $f) => [
                    ...$f,
                    'nsf_codes' => json_encode($f['nsf_codes'], JSON_UNESCAPED_UNICODE),
                    'blocks' => json_encode($f['blocks'], JSON_UNESCAPED_UNICODE),
                    'synced_at' => $now,
                ], $chunk));
            }
        });

        return count($fiches);
    }

    /**
     * @return array<string, array{code: string, title: string, level: ?int, active: bool, end_on: ?string, nsf_codes: list<string>, blocks: list<array{code: string, label: string}>}>
     */
    private function standard(ZipArchive $zip): array
    {
        $fiches = [];
        foreach ($this->rows($zip, 'Standard') as $row) {
            $code = mb_strtoupper(trim($row['Numero_Fiche'] ?? ''));
            if (preg_match('/^RNCP\d{1,6}$/', $code) !== 1) {
                continue;
            }
            $end = trim($row['Date_Fin_Enregistrement'] ?? '');
            $endOn = preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $end, $m) === 1 ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
            $level = preg_match('/^NIV(\d)$/', trim($row['Nomenclature_Europe_Niveau'] ?? ''), $l) === 1 ? (int) $l[1] : null;
            $fiches[$code] = [
                'code' => $code,
                'title' => trim($row['Intitule'] ?? ''),
                'level' => $level,
                'active' => trim($row['Actif'] ?? '') === 'ACTIVE',
                'end_on' => $endOn,
                'nsf_codes' => [],
                'blocks' => [],
            ];
        }

        return $fiches;
    }

    /**
     * Rows of the CSV of the zip whose name contains $part (« ; », UTF-8,
     * quoted), as header => value.
     *
     * @return iterable<array<string, string>>
     */
    private function rows(ZipArchive $zip, string $part): iterable
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_contains($name, '_'.$part.'_') && ! str_contains($name, '_'.$part)) {
                continue;
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $headers = null;
            while (($cells = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                if ($headers === null) {
                    $headers = array_map(fn ($h) => trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $h)), $cells);

                    continue;
                }
                if (count($cells) === count($headers)) {
                    yield array_combine($headers, array_map(fn ($c) => (string) $c, $cells));
                }
            }
            fclose($stream);

            return;
        }
    }
}
