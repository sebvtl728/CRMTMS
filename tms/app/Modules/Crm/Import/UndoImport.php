<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a finished import: the fiches it CREATED go to the trash (through
 * the models: journal, cascades), unless modified since the import
 * (updated_at > finished_at), merged or already deleted: those are kept and
 * reported on their row (« modifiée depuis l'import, conservée »). Fiches the
 * import only updated are left as they are; nothing is restored. Status
 * « undone », journal crm.import_undone (counters only). Works in slices
 * like RunImport.
 */
class UndoImport implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public const KEPT_MODIFIED = 'Modifiée depuis l\'import, conservée.';

    public const KEPT_MERGED = 'Fusionnée depuis l\'import, conservée.';

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public string $batchId, public ?string $userId = null)
    {
        $this->captureTenant();
    }

    public function handle(AuditRecorder $audit, TenantContext $tenants): void
    {
        $lock = Cache::lock('crm-import:'.$tenants->id().':'.$this->batchId, $this->timeout + 30);
        if (! $lock->get()) {
            return;
        }

        $continue = false;
        try {
            $batch = ImportBatch::query()->find($this->batchId);
            if ($batch === null || ! ($batch->canUndo() || $batch->status === ImportBatch::UNDOING)) {
                return;
            }
            $user = $this->userId === null ? $batch->user : User::query()->find($this->userId);

            $continue = ActingUser::run($user, fn () => $this->slice($batch, $audit));
        } finally {
            $lock->release();
        }

        if ($continue) {
            static::dispatch($this->batchId, $this->userId);
        }
    }

    private function slice(ImportBatch $batch, AuditRecorder $audit): bool
    {
        $started = microtime(true);

        if ($batch->status !== ImportBatch::UNDOING) {
            $options = $batch->options;
            $options['undo'] = ['removed' => 0, 'kept' => 0];
            $batch->forceFill(['status' => ImportBatch::UNDOING, 'cursor_line' => 0, 'options' => $options])->save();
        }
        $finishedAt = $batch->finished_at ?? now();

        while (true) {
            $rows = $batch->rows()->where('line', '>', $batch->cursor_line)
                ->whereRaw("created_refs <> '[]'::jsonb")
                ->orderBy('line')->limit(RunImport::CHUNK)->get();

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($batch, $rows, $finishedAt): void {
                $removed = 0;
                $kept = 0;
                foreach ($rows as $row) {
                    $notes = [];
                    // Last created first: link, then person, then entreprise.
                    foreach (array_reverse($row->created_refs) as $ref) {
                        $result = $this->remove($ref['type'], $ref['id'], $finishedAt);
                        if ($result === true) {
                            $removed++;
                        } elseif (is_string($result)) {
                            $kept++;
                            $notes[] = ['level' => 'warning', 'text' => $result];
                        }
                    }
                    if ($notes !== []) {
                        DB::table('import_rows')->where('id', $row->id)->update([
                            'messages' => json_encode([...$row->messages, ...$notes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ]);
                    }
                }

                $options = $batch->options;
                $options['undo'] = ['removed' => ($options['undo']['removed'] ?? 0) + $removed, 'kept' => ($options['undo']['kept'] ?? 0) + $kept];
                $batch->forceFill(['cursor_line' => (int) $rows->last()->line, 'options' => $options])->save();
            });

            if (microtime(true) - $started > RunImport::SLICE_SECONDS) {
                return true;
            }
        }

        $batch->forceFill(['status' => ImportBatch::UNDONE, 'undone_at' => now()])->save();
        $audit->record('crm.import_undone', $batch, [], [
            'file' => $batch->original_name,
            'removed' => $batch->options['undo']['removed'] ?? 0,
            'kept' => $batch->options['undo']['kept'] ?? 0,
        ]);

        return false;
    }

    /**
     * @return bool|string true when trashed, a message when kept, false when nothing to do
     */
    private function remove(string $type, string $id, Carbon $finishedAt): bool|string
    {
        $class = Relation::getMorphedModel($type);
        if ($class === null) {
            return false;
        }

        /** @var Model|null $model */
        $model = method_exists($class, 'withTrashed') ? $class::withTrashed()->find($id) : $class::query()->find($id);
        if ($model === null || (method_exists($model, 'trashed') && $model->trashed())) {
            return false; // already deleted (by hand, or with its entreprise)
        }
        if ($model->getAttribute('merged_into_id') !== null) {
            return self::KEPT_MERGED;
        }
        $updatedAt = $model->getAttribute('updated_at');
        // To the second: finished_at is stored without fractions, notes keep microseconds.
        if ($updatedAt instanceof Carbon && $updatedAt->copy()->startOfSecond()->greaterThan($finishedAt)) {
            return self::KEPT_MODIFIED;
        }

        $model->delete();

        return true;
    }
}
