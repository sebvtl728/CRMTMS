<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * The competence grid of the apprentice's training (Catalogue, three levels,
 * no ids) with the A / ECA / NA marks of the centre and the company side.
 * A skill is keyed by the SHA-256 of its normalised path (activity type |
 * competence | skill): marks on skills later removed from the training are
 * kept, neither shown nor printed, and counted.
 */
final class BookletGrid
{
    public const CENTRE = 'centre';

    public const COMPANY = 'company';

    public function __construct(private readonly Trainings $trainings) {}

    public static function skillKey(string $activity, string $competence, string $skill): string
    {
        $norm = fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));

        return hash('sha256', $norm($activity).'|'.$norm($competence).'|'.$norm($skill));
    }

    /**
     * @return array{types: list<array{title: string, competences: list<array{title: string, skills: list<array{key: string, title: string, centre: ?string, company: ?string}>}>}>, orphans: int}
     */
    public function tree(Candidature $candidature): array
    {
        $grid = $candidature->training_id === null ? [] : $this->trainings->competences($candidature->training_id);
        $marks = [];
        foreach (BookletEvaluation::query()->where('candidature_id', $candidature->id)->get(['skill_key', 'side', 'level']) as $e) {
            $marks[$e->skill_key][$e->side] = $e->level;
        }

        $seen = [];
        $activities = array_map(fn (array $at) => [
            'title' => $at['title'],
            'competences' => array_map(fn (array $cp) => [
                'title' => $cp['title'],
                'skills' => array_map(function (string $skill) use ($at, $cp, $marks, &$seen): array {
                    $key = self::skillKey($at['title'], $cp['title'], $skill);
                    $seen[$key] = true;

                    return ['key' => $key, 'title' => $skill, 'centre' => $marks[$key][self::CENTRE] ?? null, 'company' => $marks[$key][self::COMPANY] ?? null];
                }, $cp['skills']),
            ], $at['competences']),
        ], $grid);

        return ['types' => $activities, 'orphans' => count(array_diff_key($marks, $seen))];
    }

    /**
     * Sets (or clears, $level null) the mark of a skill of the current grid
     * on one side; $by null for the tutor (by his link).
     *
     * @throws ValidationException
     */
    public function set(Candidature $candidature, string $key, string $side, ?string $level, ?User $by): void
    {
        if (! in_array($side, BookletEvaluation::SIDES, true)) {
            throw ValidationException::withMessages(['side' => 'Côté inconnu.']);
        }
        if ($level !== null && ! in_array($level, BookletEvaluation::LEVELS, true)) {
            throw ValidationException::withMessages(['level' => 'Choisissez A, ECA ou NA.']);
        }
        if (! in_array($key, $this->keys($candidature), true)) {
            throw ValidationException::withMessages(['key' => 'Savoir-faire inconnu.']);
        }

        $existing = BookletEvaluation::query()->where('candidature_id', $candidature->id)
            ->where('skill_key', $key)->where('side', $side)->first();
        if ($level === null) {
            $existing?->delete();

            return;
        }
        if ($existing === null) {
            BookletEvaluation::query()->create(['candidature_id' => $candidature->id, 'skill_key' => $key, 'side' => $side, 'level' => $level, 'updated_by' => $by?->id]);
        } elseif ($existing->level !== $level) {
            $existing->update(['level' => $level, 'updated_by' => $by?->id]);
        }
    }

    /**
     * @return list<string>
     */
    private function keys(Candidature $candidature): array
    {
        $keys = [];
        foreach ($this->tree($candidature)['types'] as $at) {
            foreach ($at['competences'] as $cp) {
                foreach ($cp['skills'] as $skill) {
                    $keys[] = $skill['key'];
                }
            }
        }

        return $keys;
    }
}
