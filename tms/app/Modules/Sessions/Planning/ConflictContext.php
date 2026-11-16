<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Resources\ClosurePeriods;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\TrainerUnavailability;
use Illuminate\Support\Collection;

/**
 * The data the alerts of a set of candidates need, loaded once: closures,
 * placements, the séances of their trainers and rooms, unavailabilities,
 * trainees and their other séances.
 *
 * @internal
 *
 * @phpstan-import-type Candidate from Conflicts
 */
final class ConflictContext
{
    /** @var array<string, string> day => label */
    public array $closed;

    /** @var Collection<string, Room> */
    public Collection $rooms;

    /** @var Collection<int, TrainerUnavailability> */
    public Collection $unavailabilities;

    /** @var Collection<int, Seance> planned séances of the trainers and rooms */
    private Collection $seances;

    /** @var Collection<int, PlacementPeriod> */
    private Collection $placements;

    /** @var Collection<int, Enrolment> trainees of the candidate sessions */
    private Collection $enrolments;

    /** @var Collection<int, Seance> séances of other sessions where those trainees are enrolled */
    private Collection $traineeSeances;

    /** @var array<string, list<string>> session => person ids of the other séances */
    private array $traineeSeancePeople = [];

    /** @var array<string, string> person id => label */
    private array $people = [];

    /** @var array<string, string> trainer id => label */
    private array $trainers = [];

    /** @var array<string, string> session id => number */
    private array $numbers = [];

    /**
     * @param  list<string>  $sessions
     * @param  list<Candidate>  $items
     */
    public function __construct(string $from, string $to, array $sessions, array $items)
    {
        $this->closed = WorkingDays::closedDays(ClosurePeriods::between(WorkingDays::day($from), WorkingDays::day($to)), $from, $to);

        $trainerIds = array_values(array_unique(array_filter(array_column($items, 'trainer'))));
        $roomIds = array_values(array_unique(array_filter(array_column($items, 'room'))));
        $this->rooms = $roomIds === [] ? collect() : Room::withTrashed()->whereIn('id', $roomIds)->get()->keyBy('id');
        $this->seances = $trainerIds === [] && $roomIds === [] ? collect() : Seance::query()
            ->where('status', Seance::PLANNED)->whereBetween('date', [$from, $to])
            ->where(fn ($q) => $q->whereIn('trainer_id', $trainerIds)->orWhereIn('room_id', $roomIds))
            ->orderBy('date')->orderBy('starts_at')->get();
        $this->unavailabilities = $trainerIds === [] ? collect() : TrainerUnavailability::query()->whereIn('trainer_id', $trainerIds)
            ->where('starts_at', '<', Seance::instant($to, '23:59')->addMinute())->where('ends_at', '>', Seance::instant($from, '00:00'))->get();
        $trainerPeople = $trainerIds === [] ? collect() : Trainer::withTrashed()->whereIn('id', $trainerIds)->pluck('person_id', 'id');

        $this->placements = PlacementPeriod::query()->with('hosts')->whereIn('training_session_id', $sessions)
            ->where('starts_on', '<=', $to)->get()
            ->filter(fn (PlacementPeriod $p) => $p->ends_on->toDateString() >= $from || $p->hosts->contains(fn (PlacementPeriodHost $h) => $h->ends_on !== null && $h->ends_on->toDateString() >= $from))
            ->values();
        $this->enrolments = Enrolment::query()->whereIn('training_session_id', $sessions)
            ->whereIn('status', EnrolmentStatus::values(self::traineeStates()))->get(['id', 'person_id', 'training_session_id']);

        $persons = $this->enrolments->pluck('person_id')->unique()->values()->all();
        $this->traineeSeances = collect();
        if ($persons !== []) {
            $others = Enrolment::query()->whereIn('person_id', $persons)
                ->whereIn('status', EnrolmentStatus::values(self::traineeStates()))->get(['person_id', 'training_session_id']);
            foreach ($others as $e) {
                $this->traineeSeancePeople[$e->training_session_id][] = $e->person_id;
            }
            $this->traineeSeances = Seance::query()->where('status', Seance::PLANNED)->whereBetween('date', [$from, $to])
                ->whereIn('training_session_id', array_keys($this->traineeSeancePeople))
                ->orderBy('date')->orderBy('starts_at')->get();
        }

        $fiches = app(Fiches::class);
        $hostPeople = $this->placements->flatMap(fn (PlacementPeriod $p) => $p->hosts)->pluck('enrolment_id')->all();
        $personIds = [...$persons, ...$trainerPeople->values()->all(),
            ...$this->enrolments->whereIn('id', $hostPeople)->pluck('person_id')->all()];
        $labels = $fiches->personLabels(array_values(array_unique(array_map('strval', $personIds))));
        foreach ($labels as $id => $label) {
            $this->people[$id] = $label['label'];
        }
        foreach ($trainerPeople as $trainerId => $personId) {
            $this->trainers[$trainerId] = $this->people[$personId] ?? 'Le formateur';
        }
        $numbers = [...$sessions, ...$this->seances->pluck('training_session_id')->all(), ...$this->traineeSeances->pluck('training_session_id')->all()];
        $this->numbers = TrainingSession::withTrashed()->whereIn('id', array_unique($numbers))->pluck('number', 'id')->all();
    }

    /**
     * @return list<EnrolmentStatus>
     */
    private static function traineeStates(): array
    {
        return [EnrolmentStatus::Enrolled, EnrolmentStatus::Confirmed];
    }

    public function trainerName(string $trainerId): string
    {
        return $this->trainers[$trainerId] ?? 'Le formateur';
    }

    public function sessionNumber(string $sessionId): string
    {
        return $this->numbers[$sessionId] ?? 'autre session';
    }

    /**
     * Planned séances of that trainer (or room) that day, in order.
     *
     * @return Collection<int, Seance>
     */
    public function seancesOn(string $column, string $id, string $date): Collection
    {
        return $this->seances->filter(fn (Seance $s) => $s->{$column} === $id && $s->date->toDateString() === $date)->values();
    }

    public function traineeCount(string $sessionId): int
    {
        return $this->enrolments->where('training_session_id', $sessionId)->count();
    }

    /**
     * Alerts of the placements of the session that day: its common dates
     * (trainees without own dates), each trainee's own dates.
     *
     * @return list<string>
     */
    public function placementsOf(string $sessionId, string $date): array
    {
        $day = Conflicts::french($date);
        $alerts = [];
        foreach ($this->placements->where('training_session_id', $sessionId) as $period) {
            $own = $period->hosts->filter(fn (PlacementPeriodHost $h) => $h->starts_on !== null && $h->ends_on !== null);
            if ($period->starts_on->toDateString() <= $date && $period->ends_on->toDateString() >= $date) {
                $count = $this->enrolments->where('training_session_id', $sessionId)->whereNotIn('id', $own->pluck('enrolment_id')->all())->count();
                $alerts[] = "Le {$day} tombe pendant le stage « {$period->label} »"
                    .($count === 0 ? '.' : ' de '.$count.' stagiaire'.($count > 1 ? 's' : '').'.');
            }
            foreach ($own as $host) {
                $enrolment = $this->enrolments->firstWhere('id', $host->enrolment_id);
                if ($enrolment !== null && $host->starts_on?->toDateString() <= $date && $host->ends_on?->toDateString() >= $date) {
                    $name = $this->people[$enrolment->person_id] ?? 'Un stagiaire';
                    $alerts[] = "Stage de {$name} le {$day} (« {$period->label} ») : absence prévue à cette séance.";
                }
            }
        }

        return $alerts;
    }

    /**
     * The trainees of the candidate's session enrolled on another session
     * holding an overlapping séance that day.
     *
     * @param  Candidate  $c
     * @return list<array{0: string, 1: Seance}>
     */
    public function traineesBusy(array $c): array
    {
        $mine = $this->enrolments->where('training_session_id', $c['session'])->pluck('person_id')->all();
        if ($mine === []) {
            return [];
        }
        $busy = [];
        foreach ($this->traineeSeances as $other) {
            if ($other->training_session_id === $c['session'] || $other->date->toDateString() !== $c['date'] || ! Conflicts::overlap($c, $other)) {
                continue;
            }
            foreach (array_intersect($mine, $this->traineeSeancePeople[$other->training_session_id] ?? []) as $person) {
                $busy[] = [$this->people[$person] ?? 'Un stagiaire', $other];
            }
        }

        return $busy;
    }
}
