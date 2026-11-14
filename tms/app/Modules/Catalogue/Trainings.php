<?php

namespace App\Modules\Catalogue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Public service of the catalogue, the only way other modules (Crm:
 * opportunities, quotes) read trainings. Choices (options, search) offer
 * the active trainings only; find() also returns an inactive one, so that
 * what already uses it keeps showing it.
 */
final class Trainings
{
    public function find(string $id): ?TrainingData
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $training = Training::query()->with('das')->find($id);

        return $training === null ? null : TrainingData::fromModel($training);
    }

    /**
     * Active trainings whose code or title holds every word of $q
     * (accent-insensitive), optionally in one DAS.
     *
     * @return list<TrainingData>
     */
    public function search(string $q, ?string $dasId, int $limit = 20): array
    {
        if ($dasId !== null && ! Str::isUuid($dasId)) {
            return [];
        }

        $query = TrainingListQuery::search(self::active(), mb_substr(trim($q), 0, 100))
            ->when($dasId, fn (Builder $b, string $id) => $b->where('trainings.das_id', $id))
            ->limit(max(1, min($limit, 100)));

        return self::data($query);
    }

    /**
     * Every active training, by title (select options).
     *
     * @return list<TrainingData>
     */
    public function options(): array
    {
        return self::data(self::active());
    }

    /**
     * @return Builder<Training>
     */
    private static function active(): Builder
    {
        return Training::query()->where('trainings.is_active', true);
    }

    /**
     * @param  Builder<Training>  $query
     * @return list<TrainingData>
     */
    private static function data(Builder $query): array
    {
        return $query->with('das')->orderBy('trainings.title')->orderBy('trainings.id')->get()
            ->map(fn (Training $t) => TrainingData::fromModel($t))->values()->all();
    }
}
