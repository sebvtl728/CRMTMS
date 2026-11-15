<?php

namespace App\Modules\Crm\Interests;

use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * « Formations d'intérêt » of an entreprise or a person: trainings of the
 * catalogue the fiche is interested in (read through Catalogue\Trainings
 * only). A change of the list is journaled on the fiche itself
 * (crm.organisation_trainings_changed / crm.person_trainings_changed, ids
 * only), which its history shows. An inactive training stays on the fiche
 * that already has it, and cannot be added.
 */
final class FicheTrainings
{
    public const MAX = 50;

    public function __construct(private readonly AuditRecorder $audit, private readonly Trainings $trainings) {}

    /**
     * Replaces the trainings of the fiche (validated ids, see rules()).
     *
     * @param  list<string>  $trainingIds
     */
    public function sync(Organisation|Person $fiche, array $trainingIds): void
    {
        $column = self::column($fiche);
        $wanted = array_values(array_unique($trainingIds));

        DB::transaction(function () use ($fiche, $column, $wanted): void {
            // Two saves of the same fiche run one after the other (unique index, journal).
            $fiche->newQueryWithoutScopes()->whereKey($fiche->getKey())->lockForUpdate()->first();
            $before = self::ids($fiche);

            foreach (FicheTraining::query()->where($column, $fiche->getKey())->whereNotIn('training_id', $wanted)->get() as $row) {
                $row->delete();
            }
            foreach (array_diff($wanted, $before) as $trainingId) {
                FicheTraining::query()->create([$column => $fiche->getKey(), 'training_id' => $trainingId, 'created_by' => Auth::id()]);
            }

            $this->journal($fiche, $before, self::ids($fiche));
        });
    }

    /**
     * Merge of two fiches: the trainings of $drop join $keep (once each).
     * Runs inside the merge transaction.
     */
    public function merge(Organisation|Person $keep, Organisation|Person $drop): void
    {
        $column = self::column($keep);
        $before = self::ids($keep);

        foreach (FicheTraining::query()->where($column, $drop->getKey())->orderBy('id')->get() as $row) {
            if (in_array($row->training_id, $before, true)) {
                $row->delete();

                continue;
            }
            $row->setAttribute($column, $keep->getKey());
            $row->save();
        }

        $this->journal($keep, $before, self::ids($keep));
    }

    /**
     * Anonymisation: the trainings of these people (and of the fiches merged
     * into them) go. Ids only, nothing else to scrub.
     *
     * @param  list<string>  $personIds
     */
    public static function forgetPeople(array $personIds): void
    {
        FicheTraining::query()->whereIn('person_id', $personIds)->delete();
    }

    /**
     * Ids of the trainings of the fiche, sorted.
     *
     * @return list<string>
     */
    public static function ids(Organisation|Person $fiche): array
    {
        return FicheTraining::query()->where(self::column($fiche), $fiche->getKey())->orderBy('training_id')->pluck('training_id')->all();
    }

    /**
     * Whether a training is of interest to a fiche (TrainingUsage).
     */
    public static function uses(string $trainingId): bool
    {
        return FicheTraining::query()->where('training_id', $trainingId)->exists();
    }

    /**
     * List filter « Formation d'intérêt » ($table: the list's own table).
     *
     * @template TModel of Organisation|Person
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function filter(Builder $query, string $trainingId, string $table): Builder
    {
        $column = $table === 'people' ? 'person_id' : 'organisation_id';

        return $query->whereIn($table.'.id', FicheTraining::query()->select('fiche_trainings.'.$column)
            ->where('fiche_trainings.training_id', $trainingId)->whereNotNull('fiche_trainings.'.$column));
    }

    /**
     * The trainings as the fiche shows them: by DAS, then by title.
     *
     * @return list<array{id: string, code: ?string, title: string, das_id: string, das: ?string, is_active: bool}>
     */
    public function rows(Organisation|Person $fiche): array
    {
        return array_map(fn (TrainingData $t) => [
            'id' => $t->id,
            'code' => $t->code,
            'title' => $t->title,
            'das_id' => $t->das_id,
            'das' => $t->das_label,
            'is_active' => $t->is_active,
        ], self::sorted(array_values($this->trainings->findMany(self::ids($fiche)))));
    }

    /**
     * Choices of the editor: the active trainings, plus the inactive ones
     * the fiche already has (« (inactive) »); by DAS, then by title.
     *
     * @return list<array{value: string, label: string, das_id: string, das: ?string, is_active: bool}>
     */
    public function options(Organisation|Person $fiche): array
    {
        $all = $this->trainings->options();
        $offered = array_map(fn (TrainingData $t) => $t->id, $all);
        $kept = array_values(array_diff(self::ids($fiche), $offered));
        $all = [...$all, ...array_values($this->trainings->findMany($kept))];

        return array_map(fn (TrainingData $t) => [
            'value' => $t->id,
            'label' => self::label($t),
            'das_id' => $t->das_id,
            'das' => $t->das_label,
            'is_active' => $t->is_active,
        ], self::sorted($all));
    }

    /**
     * Training lines proposed on a new opportunity of the fiche: its active
     * trainings of interest, quantity 1, by title.
     *
     * @return list<array{training_id: string, quantity: string}>
     */
    public function opportunityLines(Organisation|Person $fiche): array
    {
        $active = array_filter($this->trainings->findMany(self::ids($fiche)), fn (TrainingData $t) => $t->is_active);
        usort($active, fn (TrainingData $a, TrainingData $b) => strnatcasecmp($a->title, $b->title));

        return array_map(fn (TrainingData $t) => ['training_id' => $t->id, 'quantity' => '1'], $active);
    }

    // ------------------------------------------------------------ validation

    /**
     * @return array<string, mixed>
     */
    public static function rules(Organisation|Person $fiche): array
    {
        $kept = self::ids($fiche);

        return [
            'training_ids' => ['present', 'array', 'max:'.self::MAX],
            'training_ids.*' => ['bail', 'required', 'string', 'distinct', self::available($kept)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'training_ids.present' => 'Cette valeur n\'est pas valide.',
            'training_ids.array' => 'Cette valeur n\'est pas valide.',
            'training_ids.max' => 'Indiquez au plus '.self::MAX.' formations d\'intérêt.',
            'training_ids.*.required' => 'Choisissez une formation.',
            'training_ids.*.string' => 'Cette formation n\'existe pas.',
            'training_ids.*.distinct' => 'Cette formation est déjà dans la liste.',
        ];
    }

    /**
     * @param  list<string>  $kept  trainings the fiche already has
     */
    private static function available(array $kept): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kept): void {
            $training = is_string($value) ? app(Trainings::class)->find($value) : null;
            if ($training === null) {
                $fail('Cette formation n\'existe pas.');
            } elseif (! $training->is_active && ! in_array($training->id, $kept, true)) {
                $fail('Cette formation est désactivée : choisissez-en une autre.');
            }
        };
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return 'organisation_id'|'person_id'
     */
    public static function column(Organisation|Person $fiche): string
    {
        return $fiche instanceof Person ? 'person_id' : 'organisation_id';
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function journal(Organisation|Person $fiche, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }

        $event = $fiche instanceof Organisation ? 'crm.organisation_trainings_changed' : 'crm.person_trainings_changed';
        $this->audit->record($event, $fiche, ['training_ids' => $before], ['training_ids' => $after]);
    }

    /**
     * @param  list<TrainingData>  $trainings
     * @return list<TrainingData>
     */
    private static function sorted(array $trainings): array
    {
        usort($trainings, fn (TrainingData $a, TrainingData $b) => strnatcasecmp((string) $a->das_label, (string) $b->das_label) ?: strnatcasecmp($a->title, $b->title));

        return $trainings;
    }

    private static function label(TrainingData $training): string
    {
        $label = $training->code === null ? $training->title : $training->title.' ('.$training->code.')';

        return $training->is_active ? $label : $label.' (inactive)';
    }
}
