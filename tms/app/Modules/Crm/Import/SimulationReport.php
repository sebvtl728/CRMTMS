<?php

namespace App\Modules\Crm\Import;

/**
 * Predicted outcome of an import, without any business write.
 */
final class SimulationReport
{
    /** Problem rows kept for display (the counters cover every row). */
    public const MAX_ROWS = 500;

    public int $total = 0;

    public int $toCreate = 0;

    public int $toUpdate = 0;

    public int $toSkip = 0;

    public int $errors = 0;

    /** Fiches changed by someone since their import that the file will overwrite. */
    public int $modifiedSinceImport = 0;

    /** @var list<string> blocking problems of the file (no row can be imported) */
    public array $blocking = [];

    /** @var list<string> warnings about the file */
    public array $warnings = [];

    /** @var list<array{line: int, status: string, messages: list<string>}> rows with an error, a warning or skipped */
    public array $rows = [];

    /** @var array<string, list<string>> field => values without correspondence */
    public array $unknownValues = [];

    public function add(int $line, RowOutcome $outcome): void
    {
        $this->total++;
        if ($outcome->modifiedSinceImport) {
            $this->modifiedSinceImport++;
        }
        match ($outcome->status) {
            ImportRow::CREATED => $this->toCreate++,
            ImportRow::UPDATED => $this->toUpdate++,
            ImportRow::SKIPPED => $this->toSkip++,
            default => $this->errors++,
        };

        if (($outcome->status !== ImportRow::CREATED || $outcome->messages !== []) && count($this->rows) < self::MAX_ROWS) {
            $this->rows[] = ['line' => $line, 'status' => $outcome->status, 'messages' => $outcome->texts()];
        }
    }

    public function canRun(): bool
    {
        return $this->blocking === [] && $this->total > 0;
    }

    /**
     * Summary stored on the batch (no row content).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'total' => $this->total,
            'to_create' => $this->toCreate,
            'to_update' => $this->toUpdate,
            'to_skip' => $this->toSkip,
            'errors' => $this->errors,
            'modified_since_import' => $this->modifiedSinceImport,
            'blocking' => $this->blocking,
            'warnings' => $this->warnings,
            'unknown_values' => $this->unknownValues,
        ];
    }
}
