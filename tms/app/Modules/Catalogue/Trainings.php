<?php

namespace App\Modules\Catalogue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public service of the catalogue, the only way other modules (Crm:
 * opportunities, quotes) read trainings. Choices (options, search) offer
 * the active trainings only; find() also returns an inactive one, so that
 * what already uses it keeps showing it.
 */
final class Trainings
{
    /** Morph alias of a training (import rows, history). */
    public const MORPH_ALIAS = 'training';

    /** Right needed to change the catalogue (an import of trainings included). */
    public const MANAGE_PERMISSION = Permissions::MANAGE;

    /** Keys of the values an import gives (see TrainingImport). */
    public const IMPORT_KEYS = TrainingImport::KEYS;

    public function find(string $id): ?TrainingData
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $training = Training::query()->with('das')->find($id);

        return $training === null ? null : TrainingData::fromModel($training);
    }

    /**
     * Ids of the trainings named by these titles (exact title, case and
     * accents ignored; active or not, out of the trash), keyed by the title
     * as given. Unknown titles are left out.
     *
     * @param  list<string>  $titles
     * @return array<string, string>
     */
    public function idsByTitles(array $titles): array
    {
        if ($titles === []) {
            return [];
        }
        $key = fn (string $t) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($t))));
        $byKey = [];
        foreach (Training::query()->orderBy('created_at')->get(['id', 'title']) as $training) {
            $byKey[$key($training->title)] ??= $training->id;
        }
        $found = [];
        foreach ($titles as $title) {
            if (isset($byKey[$key($title)])) {
                $found[$title] = $byKey[$key($title)];
            }
        }

        return $found;
    }

    /**
     * Competence grid of a training (activity types → competences → skills),
     * empty when none or unknown.
     *
     * @return list<array{title: string, competences: list<array{title: string, skills: list<string>}>}>
     */
    public function competences(string $id): array
    {
        $training = Str::isUuid($id) ? Training::query()->find($id) : null;

        return $training === null ? [] : ($training->competences ?? []);
    }

    /**
     * The trainings of these ids (active or not, as find()), keyed by id;
     * unknown ids are left out. One query, for lists and exports.
     *
     * @param  list<string>  $ids
     * @return array<string, TrainingData>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id) => Str::isUuid($id))));
        if ($ids === []) {
            return [];
        }

        return Training::query()->with('das')->whereIn('trainings.id', $ids)->get()
            ->mapWithKeys(fn (Training $t) => [$t->id => TrainingData::fromModel($t)])->all();
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
     * The modules of a training, in order. Durations as stored decimals
     * (« 7.00 »); an unknown training has none.
     *
     * @return list<array{id: string, title: string, duration_hours: ?string, modality_id: ?string, content: ?string, position: int}>
     */
    public function modules(string $trainingId): array
    {
        if (! Str::isUuid($trainingId)) {
            return [];
        }

        return TrainingModule::query()->where('training_id', $trainingId)->orderBy('position')->orderBy('id')->get()
            ->map(fn (TrainingModule $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'duration_hours' => $m->duration_hours,
                'modality_id' => $m->modality_id,
                'content' => $m->content,
                'position' => $m->position,
            ])->values()->all();
    }

    /**
     * Durations of the modules of these trainings, keyed by training (one
     * query; trainings without module are left out). Stored decimals.
     *
     * @param  list<string>  $trainingIds
     * @return array<string, list<?string>>
     */
    public function moduleDurations(array $trainingIds): array
    {
        $ids = array_values(array_filter($trainingIds, fn (string $id) => Str::isUuid($id)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (TrainingModule::query()->whereIn('training_id', $ids)->orderBy('position')->orderBy('id')->get(['training_id', 'duration_hours']) as $m) {
            $out[$m->training_id][] = $m->duration_hours;
        }

        return $out;
    }

    // ------------------------------------------------------------ import

    /**
     * The training (not deleted) holding this identifier of another software.
     */
    public function findBySource(string $sourceSystem, string $sourceId): ?TrainingData
    {
        $training = TrainingImport::bySource($sourceSystem, $sourceId);

        return $training === null ? null : TrainingData::fromModel($training);
    }

    /**
     * The training (not deleted, active or not) of this code, case-insensitive.
     */
    public function findByCode(string $code): ?TrainingData
    {
        $training = TrainingImport::byCode($code);

        return $training === null ? null : TrainingData::fromModel($training);
    }

    /**
     * French messages per key (IMPORT_KEYS) for these import values, checked
     * against the training $existingId (null: a creation, title and DAS
     * required, the code free).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    public function importErrors(array $values, ?string $existingId): array
    {
        $current = $existingId === null || ! Str::isUuid($existingId) ? null : Training::query()->find($existingId);

        return TrainingImport::errors(TrainingImport::normalise($values), $current);
    }

    /**
     * Creates or updates (same source identifier, else same code) the
     * training of these import values; absent keys are left as they are.
     *
     * @param  array<string, mixed>  $values  keys of IMPORT_KEYS
     *
     * @throws ValidationException (French messages) on an invalid value
     */
    public function upsertFromImport(array $values, ?string $sourceSystem, ?string $sourceId): TrainingData
    {
        $training = TrainingImport::upsert($values, $sourceSystem, $sourceId);

        return TrainingData::fromModel($training->load('das'));
    }

    /**
     * Sends to the trash a training an import created (its undo); refused
     * (false) when it is used (TrainingUsage) or already deleted.
     */
    public function deleteImported(string $id): bool
    {
        return Str::isUuid($id) && TrainingImport::delete($id);
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
