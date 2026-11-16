<?php

namespace App\Modules\Catalogue;

/**
 * Writes the ordered module list of a training: kept by id, created
 * without one, deleted when left out. Order = position in the list.
 */
final class TrainingModules
{
    /**
     * @param  list<array<string, mixed>>  $modules  validated (TrainingRequest)
     */
    public static function sync(Training $training, array $modules): void
    {
        $existing = $training->modules()->get()->keyBy('id');
        $kept = [];

        foreach ($modules as $position => $row) {
            $values = [
                'position' => $position,
                'title' => $row['title'],
                'duration_hours' => $row['duration_hours'] ?? null,
                'modality_id' => $row['modality_id'] ?? null,
                'content' => $row['content'] ?? null,
            ];
            $module = isset($row['id']) ? $existing->get($row['id']) : null;
            if ($module === null) {
                $module = $training->modules()->create($values);
            } else {
                $module->update($values);
            }
            $kept[] = $module->id;
        }

        foreach ($existing as $id => $module) {
            if (! in_array($id, $kept, true)) {
                $module->delete();
            }
        }
    }

    /**
     * Sum of the module hours as « 21.00 » (integer cents, no float).
     */
    public static function hours(Training $training): string
    {
        $cents = 0;
        foreach ($training->modules()->pluck('duration_hours') as $hours) {
            $cents += TrainingData::cents($hours === null ? null : (string) $hours) ?? 0;
        }

        return self::format($cents);
    }

    /**
     * True when the modules hold hours that do not add up to the training
     * duration (a warning, never a block). No modules or no duration: false.
     */
    public static function gap(Training $training): bool
    {
        if ($training->duration_hours === null || ! $training->modules()->exists()) {
            return false;
        }

        return self::hours($training) !== self::format(TrainingData::cents((string) $training->duration_hours) ?? 0);
    }

    private static function format(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
