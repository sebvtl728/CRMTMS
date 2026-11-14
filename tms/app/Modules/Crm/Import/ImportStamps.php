<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the imports know about a fiche, kept in import_stamps (plain
 * indexed columns: usable under RLS, unlike a jsonb lookup in import_rows):
 * the updated_at each import left it with, and whether an undo trashed it.
 * One instance per run, filled once per chunk (prefetch()).
 */
final class ImportStamps
{
    /** @var array<string, array{stamp: string|null, trashed_by_undo_at: Carbon|null}> "type:id" => what the imports left */
    private array $cache = [];

    /**
     * Loads, in one query per type, what the imports left on these fiches
     * (call once per chunk; later lookups of other fiches query one by one).
     *
     * @param  array<string, list<string>>  $ids  morph alias => fiche ids
     */
    public function prefetch(array $ids): void
    {
        $this->cache = [];
        foreach ($ids as $type => $list) {
            $list = array_values(array_unique($list));
            if ($list === []) {
                continue;
            }
            foreach ($list as $id) {
                $this->cache[$type.':'.$id] = ['stamp' => null, 'trashed_by_undo_at' => null];
            }
            $this->load($type, $list);
        }
    }

    /**
     * The last stamp an import (not undone) left on the fiche, or null.
     */
    public function lastStamp(Model $fiche): ?string
    {
        return $this->facts($fiche->getMorphClass(), (string) $fiche->getKey())['stamp'];
    }

    /**
     * Same as modifiedSinceImport(), for a record of another module given by
     * its alias, id and current stamp (updated_at to the second).
     */
    public function modifiedSinceImportOf(string $type, string $id, ?string $current): bool
    {
        $stamp = $this->facts($type, $id)['stamp'];

        return $stamp !== null && $stamp !== $current;
    }

    /**
     * Changed by someone (screen, merge...) since an import last wrote it.
     */
    public function modifiedSinceImport(Model $fiche): bool
    {
        $stamp = $this->lastStamp($fiche);

        return $stamp !== null && $stamp !== RowOutcome::stamp($fiche);
    }

    /**
     * Trashed by the undo of an import (and not deleted again by a user since).
     */
    public function trashedByUndo(Model $fiche): bool
    {
        $deletedAt = $fiche->getAttribute('deleted_at');
        $undoneAt = $this->facts($fiche->getMorphClass(), (string) $fiche->getKey())['trashed_by_undo_at'];

        return $deletedAt instanceof Carbon && $undoneAt !== null && $undoneAt->greaterThanOrEqualTo($deletedAt->copy()->startOfSecond());
    }

    /**
     * Records what a chunk of rows created or updated (bulk insert).
     *
     * @param  list<RowOutcome>  $outcomes
     */
    public static function record(ImportBatch $batch, array $outcomes): void
    {
        $records = [];
        foreach ($outcomes as $outcome) {
            foreach ([[$outcome->createdRefs, true], [$outcome->touchedRefs, false]] as [$refs, $created]) {
                foreach ($refs as $ref) {
                    $records[] = ['tenant_id' => $batch->tenant_id, 'batch_id' => $batch->id, 'subject_type' => $ref['type'],
                        'subject_id' => $ref['id'], 'stamped_at' => $ref['at'], 'created' => $created];
                }
            }
        }
        foreach (array_chunk($records, 500) as $part) {
            DB::table('import_stamps')->insert($part);
        }
    }

    /**
     * @param  list<string>  $ids  fiches trashed by this undo
     */
    public static function markTrashedByUndo(ImportBatch $batch, array $ids): void
    {
        if ($ids !== []) {
            DB::table('import_stamps')->where('batch_id', $batch->id)->where('created', true)->whereIn('subject_id', $ids)
                ->update(['trashed_by_undo_at' => now()]);
        }
    }

    public static function markUndone(ImportBatch $batch): void
    {
        DB::table('import_stamps')->where('batch_id', $batch->id)->update(['batch_undone' => true]);
    }

    /**
     * @return array{stamp: string|null, trashed_by_undo_at: Carbon|null}
     */
    private function facts(string $type, string $id): array
    {
        $key = $type.':'.$id;
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = ['stamp' => null, 'trashed_by_undo_at' => null];
            $this->load($type, [$id]);
        }

        return $this->cache[$key];
    }

    /**
     * Index (tenant_id, subject_type, subject_id), plain equalities: usable under RLS.
     *
     * @param  list<string>  $ids
     */
    private function load(string $type, array $ids): void
    {
        foreach (DB::table('import_stamps')->where('subject_type', $type)->whereIn('subject_id', $ids)
            ->where('tenant_id', app(TenantContext::class)->id())
            ->orderBy('id')->get(['subject_id', 'stamped_at', 'batch_undone', 'trashed_by_undo_at']) as $row) {
            $key = $type.':'.$row->subject_id;
            if (! $row->batch_undone && $row->stamped_at !== null) {
                $this->cache[$key]['stamp'] = $row->stamped_at; // last one wins (insertion order)
            }
            if ($row->trashed_by_undo_at !== null) {
                $at = Carbon::parse($row->trashed_by_undo_at);
                $previous = $this->cache[$key]['trashed_by_undo_at'];
                $this->cache[$key]['trashed_by_undo_at'] = $previous === null || $at->greaterThan($previous) ? $at : $previous;
            }
        }
    }

    /**
     * For an undo: every fiche the batch created ("type:id" => true) and the
     * last stamp the batch left on each fiche it created or updated.
     *
     * @return array{created: array<string, true>, stamps: array<string, string>, refs: list<array{phase: int, row: string, type: string, id: string}>}
     */
    public static function ofBatch(ImportBatch $batch): array
    {
        $created = [];
        $stamps = [];
        $refs = [];
        $phases = array_flip(UndoImport::PHASES);

        foreach ($batch->rows()->where(fn ($q) => $q->whereRaw("created_refs <> '[]'::jsonb")->orWhereRaw("touched_refs <> '[]'::jsonb"))
            ->orderBy('line')->get(['id', 'line', 'created_refs', 'touched_refs']) as $row) {
            foreach ($row->created_refs as $ref) {
                $key = $ref['type'].':'.$ref['id'];
                $created[$key] = true;
                if (isset($ref['at'])) {
                    $stamps[$key] = $ref['at'];
                }
                $refs[] = ['phase' => $phases[$ref['type']] ?? count($phases), 'line' => $row->line, 'row' => $row->id, 'type' => $ref['type'], 'id' => $ref['id']];
            }
            foreach ($row->touched_refs as $ref) {
                if (isset($ref['at'])) {
                    $stamps[$ref['type'].':'.$ref['id']] = $ref['at'];
                }
            }
        }

        // Links and notes first: a fiche is only removed once nothing hangs on it.
        usort($refs, fn (array $a, array $b) => [$a['phase'], $a['line']] <=> [$b['phase'], $b['line']]);

        return ['created' => $created, 'stamps' => $stamps, 'refs' => array_map(fn (array $r) => array_diff_key($r, ['line' => true]), $refs)];
    }
}
