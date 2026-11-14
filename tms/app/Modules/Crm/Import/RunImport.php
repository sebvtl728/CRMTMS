<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Security\DatabaseErrorLog;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Duplicates\DuplicateFinder;
use App\Modules\Crm\Support\DasLinks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a simulated import in the background, 500 rows per transaction
 * (counters and resume point saved with each chunk). A job works for at most
 * SLICE_SECONDS, then queues its continuation: no import outlives the worker
 * timeout, and a crash loses at most one chunk (rolled back). At the end:
 * report .xlsx, status « done », journal crm.import_run (counters and file
 * name, never the content).
 */
class RunImport implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public const CHUNK = 500;

    public const SLICE_SECONDS = 40;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public string $batchId)
    {
        $this->captureTenant();
    }

    public function handle(DuplicateFinder $finder, DasLinks $das, AuditRecorder $audit, TenantContext $tenants): void
    {
        $lock = Cache::lock(self::lockKey($tenants->id(), $this->batchId), $this->timeout + 30);
        if (! $lock->get()) {
            return; // another worker is on it
        }

        $continue = false;
        try {
            $batch = ImportBatch::query()->find($this->batchId);
            if ($batch === null || ! in_array($batch->status, [ImportBatch::SIMULATED, ImportBatch::RUNNING], true)) {
                return;
            }

            $continue = ActingUser::run($batch->user, fn () => $this->slice($batch, $finder, $das, $audit));
        } finally {
            $lock->release();
        }

        if ($continue) {
            static::dispatch($this->batchId);
        }
    }

    /** Held by the job working on a batch (ImportWatchdog checks it too). */
    public static function lockKey(string $tenantId, string $batchId): string
    {
        return 'crm-import:'.$tenantId.':'.$batchId;
    }

    /**
     * @return bool true when rows remain for a next job
     */
    private function slice(ImportBatch $batch, DuplicateFinder $finder, DasLinks $das, AuditRecorder $audit): bool
    {
        $started = microtime(true);

        try {
            if ($batch->status === ImportBatch::SIMULATED) {
                $batch->forceFill(['status' => ImportBatch::RUNNING, 'started_at' => now(), 'cursor_line' => 0,
                    'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0])->save();
            }

            $values = new ValueResolver($batch->target, $batch->valueMapper(), dryRun: false);
            $importer = new RowImporter($batch, $batch->fieldMap(), $values, false, $finder, $das);
            if ($importer->blockingErrors() !== []) {
                throw new ImportFileException(implode(' ', $importer->blockingErrors()));
            }

            $chunk = [];
            $chunks = 0;
            foreach ($batch->reader()->rows() as $line => $row) {
                if ($line <= $batch->cursor_line) {
                    continue; // done by an earlier job
                }
                $chunk[$line] = $row;
                if (count($chunk) === self::CHUNK) {
                    $this->chunk($batch, $importer, $values, $chunk);
                    $chunk = [];
                    self::refreshStatistics(++$chunks);
                    if (microtime(true) - $started > self::SLICE_SECONDS) {
                        return true;
                    }
                }
            }
            if ($chunk !== []) {
                $this->chunk($batch, $importer, $values, $chunk);
            }

            $batch->report_path = ImportReport::write($batch);
            $batch->forceFill(['status' => ImportBatch::DONE, 'finished_at' => now()])->save();
            $audit->record('crm.import_run', $batch, [], $this->summary($batch));

            return false;
        } catch (Throwable $e) {
            self::log($e, $batch);
            $batch->refresh();
            $options = $batch->options;
            $options['failure'] = $e instanceof ImportFileException ? $e->getMessage() : 'L\'import s\'est arrêté sur une erreur inattendue. Les lignes déjà traitées sont conservées (annulables).';
            $batch->forceFill(['status' => ImportBatch::FAILED, 'finished_at' => now(), 'options' => $options])->save();
            $audit->record('crm.import_run', $batch, [], [...$this->summary($batch), 'failed' => true]);

            return false;
        }
    }

    /**
     * After chunks 1, 2, 4, 8... (the tables double in size): fresh planner
     * statistics keep each duplicate check on its index (see migration
     * 2026_11_19_000004).
     */
    private static function refreshStatistics(int $chunks): void
    {
        if (($chunks & ($chunks - 1)) === 0) {
            DB::statement('select public.crm_refresh_statistics()');
        }
    }

    /**
     * One transaction: the rows, their import_rows and the batch counters.
     *
     * Rows first run without a savepoint each (a savepoint per row costs, and
     * PostgreSQL slows down as they pile up in one transaction). If one row
     * fails in the database or is refused, the chunk is rolled back and
     * replayed with one savepoint per row: that row becomes an error, the
     * others are imported.
     *
     * @param  array<int, array<string, string>>  $chunk
     */
    private function chunk(ImportBatch $batch, RowImporter $importer, ValueResolver $values, array $chunk): void
    {
        $values->checkpoint();
        try {
            $this->write($batch, $importer, $chunk, isolated: false);
        } catch (RetryChunk) {
            $values->restore();
            $batch->refresh();
            $this->write($batch, $importer, $chunk, isolated: true);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $chunk
     */
    private function write(ImportBatch $batch, RowImporter $importer, array $chunk, bool $isolated): void
    {
        DB::transaction(function () use ($batch, $importer, $chunk, $isolated): void {
            $importer->prefetch($chunk);
            $records = [];
            $outcomes = [];
            $counts = [ImportRow::CREATED => 0, ImportRow::UPDATED => 0, ImportRow::SKIPPED => 0, ImportRow::ERROR => 0];

            foreach ($chunk as $line => $row) {
                $outcome = $importer->import($line, $row, $isolated);
                $counts[$outcome->status]++;
                $outcomes[] = $outcome;
                $records[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $batch->tenant_id,
                    'batch_id' => $batch->id,
                    'line' => $line,
                    'source' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status' => $outcome->status,
                    'messages' => json_encode($outcome->messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'subject_type' => $outcome->subjectType,
                    'subject_id' => $outcome->subjectId,
                    'created_subject' => $outcome->createdSubject,
                    'created_refs' => json_encode($outcome->createdRefs, JSON_THROW_ON_ERROR),
                    'touched_refs' => json_encode($outcome->touchedRefs, JSON_THROW_ON_ERROR),
                ];
            }

            // Bulk insert: no model events, nothing journaled (raw values are personal data).
            foreach (array_chunk($records, 250) as $part) {
                DB::table('import_rows')->insert($part);
            }
            ImportStamps::record($batch, $outcomes);

            $batch->forceFill([
                'cursor_line' => (int) array_key_last($chunk),
                'total' => $batch->total + count($chunk),
                'created' => $batch->created + $counts[ImportRow::CREATED],
                'updated' => $batch->updated + $counts[ImportRow::UPDATED],
                'skipped' => $batch->skipped + $counts[ImportRow::SKIPPED],
                'errors' => $batch->errors + $counts[ImportRow::ERROR],
            ])->save();
        });
    }

    /**
     * A database error message carries the bound values (file content,
     * personal data): only its SQL state and statement are logged.
     */
    private static function log(Throwable $e, ImportBatch $batch): void
    {
        if (DatabaseErrorLog::queryException($e) === null) {
            report($e);

            return;
        }

        Log::error('Import CRM interrompu par une erreur de base de données', ['batch_id' => $batch->id, ...DatabaseErrorLog::context($e)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ImportBatch $batch): array
    {
        return [
            'file' => $batch->original_name,
            'target' => $batch->target->value,
            'strategy' => $batch->strategy()->value,
            'total' => $batch->total,
            'created' => $batch->created,
            'updated' => $batch->updated,
            'skipped' => $batch->skipped,
            'errors' => $batch->errors,
        ];
    }
}
