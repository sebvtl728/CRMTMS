<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled every 10 minutes (tms:crm:import-watchdog, through
 * ForEachTenant): a run without progress for ImportBatch::STUCK_MINUTES
 * (worker killed, server restarted) is resumed once from its cursor_line;
 * if it stalls again, it is marked failed (what it created can be undone).
 *
 * Safe while the original job is alive: the watchdog takes the job's own
 * lock (RunImport::lockKey) and every change is a conditional update on the
 * status and the last progress, so a worker that wrote progress wins.
 */
class ImportWatchdog
{
    /** Automatic resumes of one run before it is declared failed. */
    public const MAX_RESUMES = 1;

    public const FAILURE = 'L\'import ne progressait plus : il a été relancé automatiquement une fois, sans succès, puis arrêté. Les lignes déjà traitées sont conservées (annulables).';

    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    /**
     * Current organisme only.
     *
     * @return array{resumed: int, failed: int}
     */
    public function run(): array
    {
        $counts = ['resumed' => 0, 'failed' => 0];

        $stalled = ImportBatch::query()->where('status', ImportBatch::RUNNING)
            ->where('updated_at', '<=', now()->subMinutes(ImportBatch::STUCK_MINUTES))
            ->orderBy('updated_at')->pluck('id');

        foreach ($stalled as $id) {
            $outcome = $this->handle((string) $id);
            if ($outcome !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /**
     * @return 'resumed'|'failed'|null null when the batch moved meanwhile or its job still runs
     */
    private function handle(string $id): ?string
    {
        $lock = Cache::lock(RunImport::lockKey($this->tenants->id(), $id), 30);
        if (! $lock->get()) {
            return null; // the original job is still working on it
        }

        try {
            $batch = ImportBatch::query()->find($id);
            if ($batch === null || $batch->status !== ImportBatch::RUNNING || ! $batch->isStuck()) {
                return null;
            }

            $resumes = (int) ($batch->options['resumes'] ?? 0);
            $options = $batch->options;
            $stale = ImportBatch::query()->whereKey($id)->where('status', ImportBatch::RUNNING)
                ->where('updated_at', '<=', now()->subMinutes(ImportBatch::STUCK_MINUTES));

            if ($resumes >= self::MAX_RESUMES) {
                $options['failure'] = self::FAILURE;
                if ($stale->update(['status' => ImportBatch::FAILED, 'finished_at' => now(), 'updated_at' => now(),
                    'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]) === 0) {
                    return null;
                }
                $batch->refresh();
                $this->audit->record('crm.import_failed_marked', $batch, [], [
                    'target' => $batch->target->value, 'total' => $batch->total,
                    'created' => $batch->created, 'watchdog' => true,
                ]);

                return 'failed';
            }

            // Fresh progress timestamp: the next watchdog pass waits another STUCK_MINUTES.
            $options['resumes'] = $resumes + 1;
            if ($stale->update(['updated_at' => now(), 'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]) === 0) {
                return null;
            }
            $this->audit->record('crm.import_resumed', $batch, [], [
                'cursor_line' => $batch->cursor_line, 'resumes' => $resumes + 1,
            ]);
        } finally {
            $lock->release();
        }

        // Queued after the lock is released: the job takes it itself.
        RunImport::dispatch($id);

        return 'resumed';
    }
}
