<?php

namespace App\Modules\Crm\Interests;

use App\Modules\Catalogue\Trainings;

/**
 * Titles of trainings for a list or an export, fetched once per training
 * (one query per batch of unknown ids), joined with « ; ».
 */
final class TrainingTitles
{
    /** @var array<string, string> */
    private array $titles = [];

    /**
     * @param  iterable<FicheTraining>  $rows
     */
    public function of(iterable $rows): ?string
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->training_id;
        }
        $missing = array_values(array_diff($ids, array_keys($this->titles)));
        if ($missing !== []) {
            foreach (app(Trainings::class)->findMany($missing) as $id => $training) {
                $this->titles[$id] = $training->title;
            }
        }

        $titles = array_values(array_filter(array_map(fn (string $id) => $this->titles[$id] ?? null, $ids)));
        natcasesort($titles);

        return $titles === [] ? null : implode(' ; ', $titles);
    }
}
