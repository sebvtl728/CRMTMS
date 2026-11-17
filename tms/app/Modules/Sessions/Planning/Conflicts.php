<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Trainers\TrainerUnavailability;

/**
 * The alerts of séances (stored, or candidates being typed or previewed by
 * the generator), spec § 4.6: trainer on another overlapping séance or
 * unavailable, another séance of the session on an overlapping slot, room taken or too small for the trainees, a public holiday,
 * a closure, a placement (common dates, or own dates of a trainee: an
 * alert, never an exclusion), a trainee on an overlapping séance of
 * another session. Never a refusal. Cancelled séances and those in the
 * trash take no slot.
 *
 * forMany() loads each kind of data once for all the candidates (the
 * session calendar, the generator's preview): a bounded number of queries.
 *
 * A candidate array: session_module_id (or training_session_id), date
 * (« Y-m-d »), start / end (« H:i »), trainer_id, room_id, seance_id (the
 * séance itself, left out when it is stored).
 *
 * @phpstan-type Candidate array{id: ?string, session: string, date: string, start: string, end: string, trainer: ?string, room: ?string}
 */
final class Conflicts
{
    /**
     * @param  Seance|array<string, mixed>  $candidate
     * @return list<Conflict>
     */
    public static function for(Seance|array $candidate): array
    {
        return self::forMany([$candidate])[0];
    }

    /**
     * @param  list<Seance|array<string, mixed>>  $candidates
     * @return list<list<Conflict>> in the order of $candidates
     */
    public static function forMany(array $candidates): array
    {
        $items = self::normalise($candidates);
        if ($items === []) {
            return [];
        }
        $from = min(array_column($items, 'date'));
        $to = max(array_column($items, 'date'));
        $sessions = array_values(array_unique(array_column($items, 'session')));
        $context = new ConflictContext($from, $to, $sessions, $items);

        return array_map(fn (array $c) => self::check($c, $context), $items);
    }

    /**
     * @param  Candidate  $c
     * @return list<Conflict>
     */
    private static function check(array $c, ConflictContext $x): array
    {
        $date = $c['date'];
        $day = self::french($date);
        $out = [];

        if (($holiday = PublicHolidays::label($date)) !== null) {
            $out[] = new Conflict('holiday', "Le {$day} est férié ({$holiday}).", $date);
        }
        if (isset($x->closed[$date])) {
            $out[] = new Conflict('closure', "Le {$day} tombe pendant la fermeture « {$x->closed[$date]} ».", $date);
        }
        foreach ($x->placementsOf($c['session'], $date) as $alert) {
            $out[] = new Conflict('placement', $alert, $date);
        }

        foreach ($x->sessionSeancesOn($c['session'], $date) as $other) {
            if ($other->id !== $c['id'] && self::overlap($c, $other)) {
                $out[] = new Conflict('seance_overlap', "Une autre séance de la session a lieu le {$day} de {$other->start()} à {$other->end()} ({$x->moduleTitle($other->session_module_id)}).", $date);
            }
        }

        if ($c['trainer'] !== null) {
            $name = $x->trainerName($c['trainer']);
            foreach ($x->seancesOn('trainer_id', $c['trainer'], $date) as $other) {
                if ($other->id !== $c['id'] && self::overlap($c, $other)) {
                    $out[] = new Conflict('trainer_busy', "{$name} a déjà une séance le {$day} de {$other->start()} à {$other->end()} ({$x->sessionNumber($other->training_session_id)}).", $date);
                }
            }
            $start = Seance::instant($date, $c['start']);
            $end = Seance::instant($date, $c['end']);
            $busy = $x->unavailabilities->where('trainer_id', $c['trainer'])
                ->first(fn (TrainerUnavailability $u) => $u->starts_at->lt($end) && $u->ends_at->gt($start));
            if ($busy !== null) {
                $out[] = new Conflict('trainer_unavailable', "{$name} est indisponible le {$day} sur ce créneau.", $date);
            }
        }

        if ($c['room'] !== null && ($room = $x->rooms->get($c['room'])) !== null) {
            foreach ($x->seancesOn('room_id', $c['room'], $date) as $other) {
                if ($other->id !== $c['id'] && self::overlap($c, $other)) {
                    $out[] = new Conflict('room_busy', "La salle « {$room->name} » est déjà occupée le {$day} de {$other->start()} à {$other->end()} ({$x->sessionNumber($other->training_session_id)}).", $date);
                }
            }
            $trainees = $x->traineeCount($c['session']);
            if ($room->capacity !== null && $trainees > $room->capacity) {
                $places = $room->capacity > 1 ? "{$room->capacity} places" : "{$room->capacity} place";
                $out[] = new Conflict('room_capacity', "La salle « {$room->name} » a {$places} pour {$trainees} stagiaires inscrits.", $date);
            }
        }

        foreach ($x->traineesBusy($c) as [$person, $other]) {
            $out[] = new Conflict('trainee_busy', "{$person} a déjà une séance le {$day} de {$other->start()} à {$other->end()} ({$x->sessionNumber($other->training_session_id)}).", $date);
        }

        return $out;
    }

    /**
     * @param  Candidate  $c
     */
    public static function overlap(array $c, Seance $other): bool
    {
        return $c['start'] < $other->end() && $c['end'] > $other->start();
    }

    /**
     * @param  list<Seance|array<string, mixed>>  $candidates
     * @return list<Candidate>
     */
    private static function normalise(array $candidates): array
    {
        $moduleIds = [];
        foreach ($candidates as $c) {
            if (is_array($c) && ! is_string($c['training_session_id'] ?? null) && is_string($c['session_module_id'] ?? null)) {
                $moduleIds[] = $c['session_module_id'];
            }
        }
        $modules = $moduleIds === [] ? collect() : SessionModule::withTrashed()->whereIn('id', array_unique($moduleIds))->pluck('training_session_id', 'id');

        $items = [];
        foreach ($candidates as $c) {
            if ($c instanceof Seance) {
                $items[] = [
                    'id' => $c->id, 'session' => $c->training_session_id, 'date' => $c->date->toDateString(),
                    'start' => $c->start(), 'end' => $c->end(), 'trainer' => $c->trainer_id, 'room' => $c->room_id,
                ];

                continue;
            }
            $session = $c['training_session_id'] ?? $modules[$c['session_module_id'] ?? ''] ?? null;
            $string = fn (string $key): ?string => is_string($c[$key] ?? null) && $c[$key] !== '' ? $c[$key] : null;
            $items[] = [
                'id' => $string('seance_id'),
                'session' => is_string($session) ? $session : '',
                'date' => (string) ($string('date') ?? ''),
                'start' => substr((string) ($string('start') ?? $string('starts_at') ?? ''), 0, 5),
                'end' => substr((string) ($string('end') ?? $string('ends_at') ?? ''), 0, 5),
                'trainer' => $string('trainer_id'),
                'room' => $string('room_id'),
            ];
        }

        return $items;
    }

    /** « 2026-09-02 » → « 02/09/2026 ». */
    public static function french(string $date): string
    {
        [$y, $m, $d] = explode('-', $date);

        return "{$d}/{$m}/{$y}";
    }
}
