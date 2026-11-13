<?php

namespace App\Modules\Crm\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What undoing an import would do, without doing it: how many of the fiches
 * it created would go to the trash, how many are kept because someone
 * changed them (or merged them) since. Same checks as UndoImport; fiches
 * with data added since (a note, a link) are only known when removing, so
 * the count is a maximum.
 */
final class UndoPreview
{
    /**
     * @return array{canUndo: bool, removable: int, keptModified: int, keptMerged: int, byType: array<string, int>}
     */
    public static function for(ImportBatch $batch): array
    {
        $result = ['canUndo' => $batch->canUndo(), 'removable' => 0, 'keptModified' => 0, 'keptMerged' => 0, 'byType' => []];
        if (! $result['canUndo']) {
            return $result;
        }

        $known = ImportStamps::ofBatch($batch);
        $ids = [];
        foreach ($known['refs'] as $ref) {
            $ids[$ref['type']][] = $ref['id'];
        }

        foreach ($ids as $type => $list) {
            $class = Relation::getMorphedModel($type);
            if ($class === null) {
                continue;
            }
            foreach (array_chunk($list, 1000) as $part) {
                /** @var iterable<Model> $models */
                $models = method_exists($class, 'withTrashed') ? $class::withTrashed()->whereIn('id', $part)->get() : $class::query()->whereIn('id', $part)->get();
                foreach ($models as $model) {
                    if (method_exists($model, 'trashed') && $model->trashed()) {
                        continue;
                    }
                    if ($model->getAttribute('merged_into_id') !== null) {
                        $result['keptMerged']++;
                    } elseif (($stamp = $known['stamps'][$type.':'.$model->getKey()] ?? null) !== null && $stamp !== RowOutcome::stamp($model)) {
                        $result['keptModified']++;
                    } else {
                        $result['removable']++;
                        $result['byType'][$type] = ($result['byType'][$type] ?? 0) + 1;
                    }
                }
            }
        }

        return $result;
    }
}
