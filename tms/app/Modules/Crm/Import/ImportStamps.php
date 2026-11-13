<?php

namespace App\Modules\Crm\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the imports know about a fiche: the updated_at each import left it
 * with (« at » of created_refs / touched_refs), and whether an undo trashed it.
 */
final class ImportStamps
{
    /**
     * The last stamp an import (not undone) left on the fiche, or null.
     */
    public static function lastStamp(Model $fiche): ?string
    {
        $needle = json_encode([['id' => (string) $fiche->getKey()]], JSON_THROW_ON_ERROR);
        $row = DB::table('import_rows as r')->join('import_batches as b', 'b.id', '=', 'r.batch_id')
            ->where(fn ($q) => $q->whereRaw('r.created_refs @> ?::jsonb', [$needle])->orWhereRaw('r.touched_refs @> ?::jsonb', [$needle]))
            ->where('b.status', '<>', ImportBatch::UNDONE)
            ->orderByDesc('b.created_at')->orderByDesc('r.line')
            ->first(['r.created_refs', 'r.touched_refs']);

        if ($row === null) {
            return null;
        }

        $stamp = null;
        foreach ([$row->created_refs, $row->touched_refs] as $refs) {
            foreach (json_decode((string) $refs, true, flags: JSON_THROW_ON_ERROR) as $ref) {
                if (($ref['id'] ?? null) === (string) $fiche->getKey() && is_string($ref['at'] ?? null)) {
                    $stamp = $ref['at'];
                }
            }
        }

        return $stamp;
    }

    /**
     * Changed by someone (screen, merge...) since an import last wrote it.
     */
    public static function modifiedSinceImport(Model $fiche): bool
    {
        $stamp = self::lastStamp($fiche);

        return $stamp !== null && $stamp !== RowOutcome::stamp($fiche);
    }

    /**
     * Trashed by the undo of an import (and not deleted again by a user since).
     */
    public static function trashedByUndo(Model $fiche): bool
    {
        $deletedAt = $fiche->getAttribute('deleted_at');
        if (! $deletedAt instanceof Carbon) {
            return false;
        }
        // The undo marks what it trashed; a later deletion by a user is newer than the undo.
        $needle = json_encode([['id' => (string) $fiche->getKey(), 'undone' => true]], JSON_THROW_ON_ERROR);

        return DB::table('import_rows as r')->join('import_batches as b', 'b.id', '=', 'r.batch_id')
            ->whereRaw('r.created_refs @> ?::jsonb', [$needle])
            ->where('b.status', ImportBatch::UNDONE)
            ->where('b.undone_at', '>=', $deletedAt->copy()->startOfSecond())
            ->exists();
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
