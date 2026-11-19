<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes online tests: the draft (title, content, DAS, trainings, time
 * limit) and its publication, which freezes a new version (number n+1,
 * total of the points); the tests offered for a candidature.
 */
final class OnlineTests
{
    /**
     * @param  array<string, mixed>  $data  title, content, das_ids, training_ids, time_limit_minutes
     */
    public function create(array $data, User $by): OnlineTest
    {
        [$values, $das, $trainings] = $this->validated($data, true);

        return DB::transaction(function () use ($values, $das, $trainings): OnlineTest {
            $test = OnlineTest::query()->create($values);
            $this->link($test, $das ?? [], $trainings ?? []);

            return $test;
        });
    }

    /** @param array<string, mixed> $data keys present only */
    public function update(OnlineTest $test, array $data, User $by): OnlineTest
    {
        [$values, $das, $trainings] = $this->validated($data, false);

        return DB::transaction(function () use ($test, $values, $das, $trainings): OnlineTest {
            $test->fill($values)->save();
            $this->link($test, $das, $trainings);

            return $test->refresh();
        });
    }

    public function publish(OnlineTest $test, User $by): OnlineTestVersion
    {
        return DB::transaction(function () use ($test, $by): OnlineTestVersion {
            /** @var OnlineTest $locked */
            $locked = OnlineTest::query()->lockForUpdate()->findOrFail($test->getKey());
            $content = OnlineTestContent::validate($locked->draft_content);
            if (array_sum(array_map(fn ($s) => count($s['questions']), $content['sections'])) === 0) {
                throw ValidationException::withMessages(['content' => 'Ajoutez au moins une question avant de publier.']);
            }
            $number = (int) OnlineTestVersion::query()->where('online_test_id', $locked->id)->max('number') + 1;
            $version = OnlineTestVersion::query()->create([
                'online_test_id' => $locked->id,
                'number' => $number,
                'content' => $content,
                'total_points' => OnlineTestContent::total($content),
                'time_limit_minutes' => $locked->time_limit_minutes,
                'published_by' => $by->id,
            ]);
            $locked->forceFill(['current_version_id' => $version->id])->save();

            return $version;
        });
    }

    public function trash(OnlineTest $test): void
    {
        $test->delete();
    }

    /**
     * The published tests (not in the trash) of the training, of its DAS or
     * of the DAS given, by title.
     *
     * @return list<array{id: string, title: string, version_id: string, total_points: int, time_limit_minutes: int|null}>
     */
    public function offered(?string $trainingId, ?string $dasId): array
    {
        $dasIds = array_values(array_filter([$dasId, $trainingId !== null ? app(Trainings::class)->find($trainingId)?->das_id : null]));
        if ($trainingId === null && $dasIds === []) {
            return [];
        }
        $tests = OnlineTest::query()->whereNotNull('current_version_id')
            ->where(fn ($q) => $q
                ->when($trainingId !== null, fn ($w) => $w->orWhereIn('id', DB::table('online_test_trainings')->select('online_test_id')->where('training_id', $trainingId)))
                ->when($dasIds !== [], fn ($w) => $w->orWhereIn('id', DB::table('online_test_das')->select('online_test_id')->whereIn('das_id', $dasIds))))
            ->orderBy('title')->orderBy('id')->get();
        $versions = OnlineTestVersion::query()->whereIn('id', $tests->pluck('current_version_id'))->get()->keyBy('id');

        return $tests->map(fn (OnlineTest $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'version_id' => (string) $t->current_version_id,
            'total_points' => (int) $versions->get($t->current_version_id)?->total_points,
            'time_limit_minutes' => $versions->get($t->current_version_id)?->time_limit_minutes,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>|null, 2: list<string>|null}
     */
    private function validated(array $data, bool $creating): array
    {
        $rules = [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'between:5,240'],
            'das_ids' => ['sometimes', 'array'],
            'das_ids.*' => ['uuid', 'distinct'],
            'training_ids' => ['sometimes', 'array'],
            'training_ids.*' => ['uuid', 'distinct'],
            'content' => [$creating ? 'required' : 'sometimes', 'array'],
        ];
        $values = Validator::make($data, $rules, [
            'title.required' => 'Donnez un titre au test.',
            'title.max' => 'Le titre est trop long.',
            'time_limit_minutes.between' => 'La durée va de 5 à 240 minutes.',
            'uuid' => 'Valeur inconnue.',
            'content.required' => 'Le test est vide.',
        ])->validate();
        $das = array_key_exists('das_ids', $values) ? array_values($values['das_ids']) : null;
        $trainings = array_key_exists('training_ids', $values) ? array_values($values['training_ids']) : null;
        if ($das !== null && ReferenceItem::query()->where('list', ReferenceList::Das->value)->whereKey($das)->count() !== count($das)) {
            throw ValidationException::withMessages(['das_ids' => 'Choisissez des DAS de l\'organisme.']);
        }
        if ($trainings !== null && count(app(Trainings::class)->findMany($trainings)) !== count($trainings)) {
            throw ValidationException::withMessages(['training_ids' => 'Choisissez des formations du catalogue.']);
        }
        $out = array_intersect_key($values, array_flip(['title', 'time_limit_minutes']));
        if (array_key_exists('title', $out)) {
            $out['title'] = trim((string) $out['title']);
        }
        if (array_key_exists('content', $values)) {
            $out['draft_content'] = OnlineTestContent::validate($values['content']);
        }

        return [$out, $das, $trainings];
    }

    /**
     * @param  list<string>|null  $das
     * @param  list<string>|null  $trainings
     */
    private function link(OnlineTest $test, ?array $das, ?array $trainings): void
    {
        if ($das !== null) {
            DB::table('online_test_das')->where('online_test_id', $test->id)->delete();
            foreach ($das as $id) {
                DB::table('online_test_das')->insert(['tenant_id' => $test->tenant_id, 'online_test_id' => $test->id, 'das_id' => $id]);
            }
        }
        if ($trainings !== null) {
            DB::table('online_test_trainings')->where('online_test_id', $test->id)->delete();
            foreach ($trainings as $id) {
                DB::table('online_test_trainings')->insert(['tenant_id' => $test->tenant_id, 'online_test_id' => $test->id, 'training_id' => $id]);
            }
        }
    }
}
