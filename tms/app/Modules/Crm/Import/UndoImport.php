<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Crm\Timeline\LastContact;
use App\Modules\Crm\Timeline\Note;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a finished import: the fiches it CREATED go to the trash, in this
 * order: notes, links, people, entreprises. A fiche is kept (and reported
 * on its row) when:
 * - someone changed it: its updated_at is not the one the import left
 *   (stamps in created_refs / touched_refs), even during the run;
 * - something not created by this import hangs on it (LinkedData: note,
 *   link, attachment, consent, and what later modules register), e.g.
 *   added by a user or by a later import;
 * - it was merged since.
 * Fiches the import only updated are left as they are; nothing is restored.
 * The last contact of a fiche whose imported notes were removed is
 * recomputed from what remains (LastContact::recompute).
 * A fiche is only trashed once nothing hangs on it, so the model cascade
 * never removes a link or a note the import did not create. Trashed fiches
 * are marked (« undone ») so that a re-import may bring them back (and only
 * them). Status « undone », journal crm.import_undone (counters only).
 * Works in slices like RunImport.
 */
class UndoImport implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    /** Order of removal (morph aliases). */
    public const PHASES = ['note', 'affiliation', 'person', 'organisation'];

    public const KEPT_MODIFIED = 'Modifiée depuis l\'import, conservée.';

    public const KEPT_MERGED = 'Fusionnée depuis l\'import, conservée.';

    public const KEPT_LINKED = 'Liée à des données ajoutées depuis l\'import, conservée.';

    public int $tries = 1;

    public int $timeout = 85;

    /**
     * Fiches whose imported notes this chunk removed: their last contact is
     * recomputed before the chunk commits.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $recontacted = [];

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

        // cursor_line: index in the ordered list of the batch's created fiches.
        $known = ImportStamps::ofBatch($batch);
        $refs = array_slice($known['refs'], $batch->cursor_line);

        foreach (array_chunk($refs, RunImport::CHUNK) as $part) {
            DB::transaction(function () use ($batch, $part, $known): void {
                $removed = 0;
                $kept = 0;
                $notes = [];
                $undone = [];
                foreach ($part as $ref) {
                    $result = $this->remove($ref['type'], $ref['id'], $known);
                    if ($result === true) {
                        $removed++;
                        $undone[$ref['row']][] = $ref['id'];
                    } elseif (is_string($result)) {
                        $kept++;
                        $notes[$ref['row']][] = ['level' => 'warning', 'text' => $result];
                    }
                }

                foreach ($this->recontacted as $subject) {
                    LastContact::recompute($subject[0], $subject[1]);
                }
                $this->recontacted = [];

                $this->annotate($notes, $undone);
                ImportStamps::markTrashedByUndo($batch, array_merge([], ...array_values($undone)));
                $options = $batch->options;
                $options['undo'] = ['removed' => ($options['undo']['removed'] ?? 0) + $removed, 'kept' => ($options['undo']['kept'] ?? 0) + $kept];
                $batch->forceFill(['cursor_line' => $batch->cursor_line + count($part), 'options' => $options])->save();
            });

            if (microtime(true) - $started > RunImport::SLICE_SECONDS) {
                return true;
            }
        }

        ImportStamps::markUndone($batch);
        $batch->forceFill(['status' => ImportBatch::UNDONE, 'undone_at' => now()])->save();
        $audit->record('crm.import_undone', $batch, [], [
            'removed' => $batch->options['undo']['removed'] ?? 0,
            'kept' => $batch->options['undo']['kept'] ?? 0,
        ]);

        return false;
    }

    /**
     * @param  array{created: array<string, true>, stamps: array<string, string>}  $known
     * @return bool|string true when trashed, a message when kept, false when nothing to do
     */
    private function remove(string $type, string $id, array $known): bool|string
    {
        $class = Relation::getMorphedModel($type);
        if ($class === null) {
            return false;
        }

        /** @var Model|null $model */
        $model = method_exists($class, 'withTrashed') ? $class::withTrashed()->find($id) : $class::query()->find($id);
        if ($model === null || (method_exists($model, 'trashed') && $model->trashed())) {
            return false; // already deleted
        }
        if ($model->getAttribute('merged_into_id') !== null) {
            return self::KEPT_MERGED;
        }
        $stamp = $known['stamps'][$type.':'.$id] ?? null;
        if ($stamp !== null && $stamp !== RowOutcome::stamp($model)) {
            return self::KEPT_MODIFIED;
        }
        if (($model instanceof Organisation || $model instanceof Person) && LinkedData::any($model)) {
            return self::KEPT_LINKED;
        }

        $model->delete();
        if ($model instanceof Note) {
            $this->recontacted[$model->notable_type.':'.$model->notable_id] = [$model->notable_type, $model->notable_id];
        }

        return true;
    }

    /**
     * Adds the « kept » messages to the rows and marks the refs trashed by
     * this undo (a re-import may restore those, never a fiche a user deleted).
     *
     * @param  array<string, list<array{level: string, text: string}>>  $notes  row id => messages
     * @param  array<string, list<string>>  $undone  row id => fiche ids
     */
    private function annotate(array $notes, array $undone): void
    {
        $ids = array_values(array_unique([...array_keys($notes), ...array_keys($undone)]));
        if ($ids === []) {
            return;
        }

        foreach (ImportRow::query()->whereIn('id', $ids)->get(['id', 'messages', 'created_refs']) as $row) {
            $marked = $undone[$row->id] ?? [];
            $refs = array_map(fn (array $ref) => in_array($ref['id'], $marked, true) ? [...$ref, 'undone' => true] : $ref, $row->created_refs);
            DB::table('import_rows')->where('id', $row->id)->update([
                'messages' => json_encode([...$row->messages, ...$notes[$row->id] ?? []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_refs' => json_encode($refs, JSON_THROW_ON_ERROR),
            ]);
        }
    }
}
