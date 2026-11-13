<?php

namespace App\Modules\Crm\Import;

use App\Modules\Crm\Duplicates\DuplicateFinder;
use App\Modules\Crm\Import\Readers\CsvReader;
use App\Modules\Crm\Support\DasLinks;

/**
 * Dry run of an import: every row goes through the same code as the real
 * run (RowImporter) without any business write (no fiche, no reference
 * value, no import row). The chosen mapping is kept on the batch, which
 * becomes « simulated » (runnable) when nothing blocks.
 */
final class Simulator
{
    public function __construct(private readonly DuplicateFinder $finder, private readonly DasLinks $das) {}

    /**
     * @throws ImportFileException once the batch was run (its mapping is then fixed)
     */
    public function run(ImportBatch $batch, FieldMap $map, ValueMapper $values): SimulationReport
    {
        if (! $batch->canSimulate()) {
            throw new ImportFileException('Cet import a déjà été lancé : il ne peut plus être simulé.');
        }

        $report = new SimulationReport;
        $reader = $batch->reader();
        $report->warnings = $reader->warnings();
        if ($reader instanceof CsvReader) {
            array_unshift($report->warnings, sprintf('Fichier lu en %s, séparateur « %s ».', $reader->encoding(), $reader->delimiter() === "\t" ? 'tabulation' : $reader->delimiter()));
        }

        $resolver = new ValueResolver($batch->target, $values, dryRun: true);
        $importer = new RowImporter($batch, $map, $resolver, true, $this->finder, $this->das);
        $report->blocking = $importer->blockingErrors();

        if ($report->blocking === []) {
            $chunk = [];
            foreach ($reader->rows() as $line => $row) {
                $chunk[$line] = $row;
                if (count($chunk) === RunImport::CHUNK) {
                    $this->simulateChunk($importer, $chunk, $report);
                    $chunk = [];
                }
            }
            $this->simulateChunk($importer, $chunk, $report);
        }
        $report->unknownValues = $resolver->unknownValues();
        if ($report->modifiedSinceImport > 0) {
            $report->warnings[] = $report->modifiedSinceImport === 1
                ? '1 fiche modifiée depuis son import sera mise à jour avec les valeurs du fichier.'
                : $report->modifiedSinceImport.' fiches modifiées depuis leur import seront mises à jour avec les valeurs du fichier.';
        }

        $batch->field_map = $map->toArray();
        $batch->value_map = $values->toArray();
        $batch->simulation = $report->summary();
        $batch->total = $report->total;
        $batch->status = $report->canRun() ? ImportBatch::SIMULATED : ImportBatch::UPLOADED;
        $batch->save();

        return $report;
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function simulateChunk(RowImporter $importer, array $chunk, SimulationReport $report): void
    {
        $importer->prefetch($chunk);
        foreach ($chunk as $line => $row) {
            $report->add($line, $importer->import($line, $row));
        }
    }
}
