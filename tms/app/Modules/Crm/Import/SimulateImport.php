<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Security\DatabaseErrorLog;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Simulation of an import in the background (the wizard polls the batch).
 * The mapping was recorded by ImportBatch::startSimulation(); progress is
 * written after each chunk (cursor_line = last file line analysed, which
 * also keeps the batch from looking stuck). The result lands on the batch:
 * « simulated » (runnable) or « uploaded » with its blocking problems; a
 * failure puts it back to « uploaded » with options.simulation_error.
 */
class SimulateImport implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public int $tries = 1;

    /** A 50 000-row file takes about a minute and a half. */
    public int $timeout = 900;

    /** Horizon runs the other jobs for 60 s at most: simulations have their own queue (config/horizon.php). */
    public const LONG_CONNECTION = 'redis-long';

    public const LONG_QUEUE = 'imports';

    public function __construct(public string $batchId)
    {
        $this->captureTenant();
        if (config('queue.default') === 'redis') {
            $this->onConnection(self::LONG_CONNECTION)->onQueue(self::LONG_QUEUE);
        }
    }

    public function handle(Simulator $simulator, TenantContext $tenants): void
    {
        $lock = Cache::lock('crm-import:'.$tenants->id().':'.$this->batchId, $this->timeout + 30);
        if (! $lock->get()) {
            return;
        }

        try {
            $batch = ImportBatch::query()->find($this->batchId);
            if ($batch === null || $batch->status !== ImportBatch::SIMULATING) {
                return;
            }

            try {
                ActingUser::run($batch->user, fn () => $simulator->run($batch, $batch->fieldMap(), $batch->valueMapper(), function (int $line) use ($batch): void {
                    ImportBatch::query()->whereKey($batch->id)->where('status', ImportBatch::SIMULATING)
                        ->update(['cursor_line' => $line, 'updated_at' => now()]);
                }));
            } catch (Throwable $e) {
                $this->fail($batch, $e);
            }
        } finally {
            $lock->release();
        }
    }

    private function fail(ImportBatch $batch, Throwable $e): void
    {
        if (! $e instanceof ImportFileException) {
            Log::error('Simulation d\'import interrompue', ['batch_id' => $batch->id, ...DatabaseErrorLog::context($e)]);
        }
        $options = $batch->options;
        $options['simulation_error'] = $e instanceof ImportFileException || $e instanceof \InvalidArgumentException
            ? $e->getMessage()
            : 'La simulation s\'est arrêtée sur une erreur inattendue. Réessayez ; si le problème persiste, contactez l\'assistance.';

        // Only if still waiting for this job (not marked failed in the meantime).
        ImportBatch::query()->whereKey($batch->id)->where('status', ImportBatch::SIMULATING)
            ->update(['status' => ImportBatch::UPLOADED, 'options' => json_encode($options, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
}
