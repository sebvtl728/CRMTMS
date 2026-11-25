<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The company visits of an apprentice (date, points discussed, trainer),
 * typed by the trainer; deleted softly.
 */
final class BookletVisits
{
    /**
     * @return list<array{id: string, visited_on: string, points: string, trainer_user_id: ?string, trainer: ?string}>
     */
    public static function list(Candidature $candidature): array
    {
        $visits = BookletVisit::query()->where('candidature_id', $candidature->id)->orderBy('visited_on')->orderBy('created_at')->get();
        $names = User::query()->whereIn('id', $visits->pluck('trainer_user_id')->filter()->unique()->values())->pluck('name', 'id');

        return $visits->map(fn (BookletVisit $v) => [
            'id' => $v->id,
            'visited_on' => $v->visited_on->toDateString(),
            'points' => (string) $v->points,
            'trainer_user_id' => $v->trainer_user_id,
            'trainer' => $v->trainer_user_id === null ? null : ($names[$v->trainer_user_id] ?? null),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data  visited_on, points, trainer_user_id
     *
     * @throws ValidationException
     */
    public static function create(Candidature $candidature, array $data, User $by): BookletVisit
    {
        return BookletVisit::query()->create([...self::validated($data, $by), 'candidature_id' => $candidature->id, 'created_by' => $by->id, 'updated_by' => $by->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function update(BookletVisit $visit, array $data, User $by): void
    {
        $visit->update([...self::validated($data, $by), 'updated_by' => $by->id]);
    }

    public static function delete(BookletVisit $visit, User $by): void
    {
        $visit->forceFill(['updated_by' => $by->id])->save();
        $visit->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{visited_on: string, points: string, trainer_user_id: string}
     *
     * @throws ValidationException
     */
    private static function validated(array $data, User $by): array
    {
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();
        $valid = Validator::make($data, [
            'visited_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'points' => ['required', 'string', 'max:'.BookletVisit::MAX_POINTS],
            'trainer_user_id' => ['nullable', 'uuid'],
        ], [
            'visited_on.required' => 'Indiquez la date de la visite.',
            'visited_on.date_format' => 'Date invalide.',
            'visited_on.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'points.required' => 'Indiquez les points abordés.',
            'points.max' => '3 000 caractères au plus.',
            'trainer_user_id.uuid' => 'Formateur inconnu.',
        ])->validate();

        $trainer = $valid['trainer_user_id'] ?? null;
        if ($trainer !== null && ! User::query()->whereKey($trainer)->exists()) {
            throw ValidationException::withMessages(['trainer_user_id' => 'Formateur inconnu.']);
        }

        return ['visited_on' => $valid['visited_on'], 'points' => trim((string) $valid['points']), 'trainer_user_id' => $trainer ?? $by->id];
    }
}
