<?php

namespace App\Modules\Sessions\Planning;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes or cancels several séances of one session at once (« Modifier la
 * sélection », « Annuler la sélection »): each séance goes through
 * Seances::reschedule / Seances::cancel (locks, closed months, attendance
 * review, SeanceChanged), all in one transaction: one séance refused and
 * nothing changes, the error names its day. Only the ticked groups change:
 * trainer; room and place; hours (start, end, break; the day stays).
 */
final class SeanceBatch
{
    public const MAX = 300;

    public const NOTHING = 'Cochez au moins un changement : formateur, salle ou lieu, horaires.';

    public function __construct(private readonly Seances $seances) {}

    /**
     * @param  array<string, mixed>  $data  seance_ids, apply_trainer, trainer_id, apply_place, room_id, place_text, apply_hours, starts_at, ends_at, break_minutes
     * @return list<Seance> the séances as saved
     *
     * @throws ValidationException
     */
    public function update(TrainingSession $session, array $data): array
    {
        $seances = self::selected($session, $data);
        $changes = [];
        if (filter_var($data['apply_trainer'] ?? false, FILTER_VALIDATE_BOOL)) {
            $changes['trainer_id'] = $data['trainer_id'] ?? null;
        }
        if (filter_var($data['apply_place'] ?? false, FILTER_VALIDATE_BOOL)) {
            $changes['room_id'] = $data['room_id'] ?? null;
            $changes['place_text'] = $data['place_text'] ?? null;
        }
        if (filter_var($data['apply_hours'] ?? false, FILTER_VALIDATE_BOOL)) {
            $changes['starts_at'] = $data['starts_at'] ?? null;
            $changes['ends_at'] = $data['ends_at'] ?? null;
            $changes['break_minutes'] = $data['break_minutes'] ?? null;
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['batch' => self::NOTHING]);
        }
        // The values themselves are checked once, on the first séance, with their own field errors.
        $first = $seances->first();
        Seances::validated($session, [...self::current($first), ...$changes], $first);

        return self::each($seances, fn (Seance $s) => $this->seances->reschedule($s, $changes));
    }

    /**
     * @param  array<string, mixed>  $data  seance_ids, reason
     * @return list<Seance>
     *
     * @throws ValidationException
     */
    public function cancel(TrainingSession $session, array $data): array
    {
        $seances = self::selected($session, $data);
        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
        Validator::make(['reason' => $reason], ['reason' => ['required', 'max:300']], [
            'reason.required' => 'Indiquez le motif de l\'annulation.',
            'reason.max' => 'Le motif ne doit pas dépasser 300 caractères.',
        ])->validate();

        return self::each($seances, fn (Seance $s) => $this->seances->cancel($s, $reason));
    }

    /**
     * The ticked séances, of this session only (404 otherwise), in a fixed
     * order (module, then id: the locks are always taken in that order).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Seance>
     *
     * @throws ValidationException
     */
    private static function selected(TrainingSession $session, array $data): Collection
    {
        $ids = $data['seance_ids'] ?? null;
        Validator::make(['seance_ids' => $ids], [
            'seance_ids' => ['required', 'array', 'max:'.self::MAX],
            'seance_ids.*' => ['uuid'],
        ], [
            'seance_ids.required' => 'Cochez au moins une séance.',
            'seance_ids.max' => 'Pas plus de '.self::MAX.' séances à la fois.',
            'seance_ids.*' => 'Séance introuvable.',
        ])->validate();
        /** @var list<string> $ids */
        $ids = array_values(array_unique($ids));
        $seances = Seance::query()->where('training_session_id', $session->id)->whereKey($ids)
            ->orderBy('session_module_id')->orderBy('id')->get();
        abort_unless($seances->count() === count($ids), 404);

        return $seances;
    }

    /**
     * @param  Collection<int, Seance>  $seances
     * @param  callable(Seance): Seance  $write
     * @return list<Seance>
     */
    private static function each(Collection $seances, callable $write): array
    {
        return DB::transaction(function () use ($seances, $write): array {
            $saved = [];
            foreach ($seances as $seance) {
                $day = Conflicts::french($seance->date->toDateString());
                try {
                    $saved[] = $write($seance);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['batch' => "Séance du {$day} : ".collect($e->errors())->flatten()->first()]);
                } catch (UserFacingHttpException $e) {
                    throw new UserFacingHttpException($e->getStatusCode(), "Séance du {$day} : ".$e->getMessage(), $e);
                }
            }

            return $saved;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function current(Seance $seance): array
    {
        return [
            'session_module_id' => $seance->session_module_id,
            'date' => $seance->date->toDateString(),
            'starts_at' => $seance->start(),
            'ends_at' => $seance->end(),
            'break_minutes' => $seance->break_minutes,
            'trainer_id' => $seance->trainer_id,
            'room_id' => $seance->room_id,
            'place_text' => $seance->place_text,
            'note' => $seance->note,
        ];
    }
}
