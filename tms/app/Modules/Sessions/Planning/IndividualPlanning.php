<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The individual planning of a trainee (spec 2026-11-24): the séances of
 * his session within his entry and leaving days, without the ones he is
 * exempted from (a séance, a whole module), plus the séances of other
 * sessions added to him, at his own hours where he has some. The one
 * source of « who is expected at which séance, for how long »: every reader
 * (attendance, signing, hours, documents, reminders, follow-up) goes
 * through it; no other class reads enrolment_adjustments.
 */
final class IndividualPlanning
{
    /**
     * The trainees expected at the séance, as a query on enrolments.
     *
     * @return Builder<Enrolment>
     */
    public static function expectedAt(Seance $seance): Builder
    {
        $date = $seance->date->toDateString();

        return Enrolment::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('training_session_id', $seance->training_session_id)
                    ->whereNotExists(fn (QueryBuilder $q) => self::exemptionOf($q, $seance)))
                // Trainees of other sessions the séance was added to (catch-up).
                ->orWhereIn('id', self::addedTo($seance)))
            ->whereIn('status', Attendances::ATTENDING)
            ->where(fn (Builder $q) => $q->whereNull('entered_on')->orWhere('entered_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $date));
    }

    /**
     * The trainees of other sessions the séance was added to (whatever their
     * state or dates): the « Rattrapage » rows of the host session.
     *
     * @return Collection<int, Enrolment>
     */
    public static function guestsAt(Seance $seance): Collection
    {
        return Enrolment::query()->whereIn('id', self::addedTo($seance))->orderBy('id')->get();
    }

    /**
     * How many trainees of other sessions each séance expects (catch-ups,
     * same state and date rules as expectedAt), one query.
     *
     * @param  Collection<int, Seance>  $seances
     * @return array<string, int> séance id => count
     */
    public static function guestCounts(Collection $seances): array
    {
        if ($seances->isEmpty()) {
            return [];
        }

        return EnrolmentAdjustment::query()->join('enrolments', 'enrolments.id', '=', 'enrolment_adjustments.enrolment_id')
            ->join('seances', 'seances.id', '=', 'enrolment_adjustments.seance_id')
            ->where('enrolment_adjustments.kind', EnrolmentAdjustment::ADDED_SEANCE)
            ->whereIn('enrolment_adjustments.seance_id', $seances->pluck('id')->all())
            ->whereIn('enrolments.status', Attendances::ATTENDING)
            ->where(fn ($q) => $q->whereNull('enrolments.entered_on')->orWhereColumn('enrolments.entered_on', '<=', 'seances.date'))
            ->where(fn ($q) => $q->whereNull('enrolments.left_on')->orWhereColumn('enrolments.left_on', '>=', 'seances.date'))
            ->groupBy('enrolment_adjustments.seance_id')
            ->selectRaw('enrolment_adjustments.seance_id as seance_id, count(*) as n')->toBase()->get()
            ->mapWithKeys(fn ($r) => [(string) $r->seance_id => (int) $r->n])->all();
    }

    /**
     * @return Builder<EnrolmentAdjustment>
     */
    private static function addedTo(Seance $seance): Builder
    {
        return EnrolmentAdjustment::query()->select('enrolment_id')
            ->where('kind', EnrolmentAdjustment::ADDED_SEANCE)->where('seance_id', $seance->id);
    }

    /** Whether the trainee is exempted from the séance (itself or its module). */
    public static function exempted(string $enrolmentId, Seance $seance): bool
    {
        return EnrolmentAdjustment::query()->where('enrolment_id', $enrolmentId)
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_SEANCE)->where('seance_id', $seance->id))
                ->orWhere(fn (Builder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->where('session_module_id', $seance->session_module_id)))
            ->exists();
    }

    /**
     * The hours of each trainee at the séance: his own hours where he has
     * some, kept within the séance (a séance moved since: the overlap, the
     * séance's hours when nothing overlaps), else the séance's. One query.
     *
     * @param  list<string>  $enrolmentIds
     * @return array<string, array{start: string, end: string, break: int, minutes: int}>
     */
    public static function times(Seance $seance, array $enrolmentIds): array
    {
        $own = $enrolmentIds === [] ? collect() : EnrolmentAdjustment::query()->where('seance_id', $seance->id)
            ->whereIn('enrolment_id', $enrolmentIds)->whereNotNull('starts_at')
            ->get(['enrolment_id', 'starts_at', 'ends_at', 'break_minutes'])->keyBy('enrolment_id');
        $out = [];
        foreach ($enrolmentIds as $id) {
            $out[$id] = self::hoursOf($seance, $own[$id] ?? null);
        }

        return $out;
    }

    /** His duration at the séance, in minutes (see times()). */
    public static function minutesAt(string $enrolmentId, Seance $seance): int
    {
        return self::times($seance, [$enrolmentId])[$enrolmentId]['minutes'];
    }

    /**
     * @return array{start: string, end: string, break: int, minutes: int}
     */
    public static function hoursOf(Seance $seance, ?EnrolmentAdjustment $own): array
    {
        $default = ['start' => $seance->start(), 'end' => $seance->end(), 'break' => $seance->break_minutes, 'minutes' => $seance->duration_minutes];
        if ($own === null || $own->starts_at === null || $own->ends_at === null) {
            return $default;
        }
        $start = max(substr($own->starts_at, 0, 5), $seance->start());
        $end = min(substr($own->ends_at, 0, 5), $seance->end());
        if ($end <= $start) {
            return $default;
        }
        $span = self::minutesOfDay($end) - self::minutesOfDay($start);
        $break = min((int) $own->break_minutes, $span - 1);

        return ['start' => $start, 'end' => $end, 'break' => $break, 'minutes' => $span - $break];
    }

    /**
     * His planned minutes (spec § 6): the base (his planned hours before the
     * first adjustment) − per module of his session, the whole module when
     * exempted, else the séances exempted and the minutes his own hours
     * miss, capped at the module's planned minutes − + the séances added.
     * Cancelled and deleted séances count nothing; never below 0.
     */
    public static function plannedMinutes(Enrolment $enrolment): int
    {
        $session = TrainingSession::withTrashed()->findOrFail($enrolment->training_session_id);
        $base = SessionModules::minutesOf($enrolment->planned_hours_base ?? $enrolment->planned_hours ?? $session->planned_hours) ?? 0;
        $cuts = self::moduleCuts(collect([$enrolment]))[$enrolment->id];

        return max(0, $base - array_sum($cuts['modules']) + $cuts['added']);
    }

    /**
     * Per trainee, what his planning takes off each module of his session
     * (capped at the module's planned minutes; the whole of it when
     * exempted) and the minutes of the séances added. Two queries.
     *
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, array{modules: array<string, int>, added: int}>
     */
    public static function moduleCuts(Collection $enrolments): array
    {
        $ids = $enrolments->pluck('id')->all();
        $modules = SessionModule::query()->whereIn('training_session_id', $enrolments->pluck('training_session_id')->unique()->values()->all())
            ->get(['id', 'training_session_id', 'planned_minutes'])->groupBy('training_session_id');
        $adjustments = $ids === [] ? collect() : EnrolmentAdjustment::query()->with('seance')->whereIn('enrolment_id', $ids)->get()->groupBy('enrolment_id');

        $out = [];
        foreach ($enrolments as $enrolment) {
            $mine = $adjustments[$enrolment->id] ?? collect();
            $exempt = $mine->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->pluck('session_module_id')->all();
            $byModule = [];
            $added = 0;
            foreach ($mine as $adjustment) {
                $seance = $adjustment->seance;
                if ($seance === null || $seance->trashed() || $seance->isCancelled()) {
                    continue;
                }
                $hours = self::hoursOf($seance, $adjustment);
                match ($adjustment->kind) {
                    EnrolmentAdjustment::ADDED_SEANCE => $added += $hours['minutes'],
                    EnrolmentAdjustment::EXEMPT_SEANCE => $byModule[$seance->session_module_id] = ($byModule[$seance->session_module_id] ?? 0) + $seance->duration_minutes,
                    EnrolmentAdjustment::OWN_HOURS => $byModule[$seance->session_module_id] = ($byModule[$seance->session_module_id] ?? 0) + $seance->duration_minutes - $hours['minutes'],
                    default => null,
                };
            }
            $cuts = [];
            foreach ($modules[$enrolment->training_session_id] ?? [] as $module) {
                $planned = (int) ($module->planned_minutes ?? 0);
                $cuts[$module->id] = in_array($module->id, $exempt, true) ? $planned : min($byModule[$module->id] ?? 0, $planned);
            }
            $out[$enrolment->id] = ['modules' => $cuts, 'added' => $added];
        }

        return $out;
    }

    /**
     * The modules each trainee is exempted from (one query).
     *
     * @param  list<string>  $enrolmentIds
     * @return array<string, list<string>> enrolment id => module ids
     */
    public static function exemptModules(array $enrolmentIds): array
    {
        return $enrolmentIds === [] ? [] : EnrolmentAdjustment::query()->whereIn('enrolment_id', $enrolmentIds)
            ->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->get(['enrolment_id', 'session_module_id'])
            ->groupBy('enrolment_id')->map(fn ($rows) => $rows->pluck('session_module_id')->map(fn ($id) => (string) $id)->values()->all())->all();
    }

    /**
     * The (trainee, séance) pairs exempted, as « enrolment id|séance id »
     * keys, the séance itself or its module (two queries).
     *
     * @param  Collection<int, Seance>  $seances
     * @param  list<string>  $enrolmentIds
     * @return array<string, true>
     */
    public static function exemptPairs(Collection $seances, array $enrolmentIds): array
    {
        if ($seances->isEmpty() || $enrolmentIds === []) {
            return [];
        }
        $out = [];
        foreach (EnrolmentAdjustment::query()->whereIn('enrolment_id', $enrolmentIds)->where('kind', EnrolmentAdjustment::EXEMPT_SEANCE)
            ->whereIn('seance_id', $seances->pluck('id')->all())->get(['enrolment_id', 'seance_id']) as $row) {
            $out[$row->enrolment_id.'|'.$row->seance_id] = true;
        }
        foreach (self::exemptModules($enrolmentIds) as $enrolmentId => $moduleIds) {
            foreach ($seances->whereIn('session_module_id', $moduleIds) as $seance) {
                $out[$enrolmentId.'|'.$seance->id] = true;
            }
        }

        return $out;
    }

    /**
     * The séances of each trainee's planning, in order, at his hours: those
     * of his session within his entry and leaving days (and $from / $to),
     * not exempted, plus the séances added to him. Cancelled ones only with
     * $withCancelled; deleted ones never. A fixed number of queries.
     *
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, list<PlannedSeance>>
     */
    public static function seancesOf(Collection $enrolments, ?string $from = null, ?string $to = null, bool $withCancelled = false): array
    {
        $ids = $enrolments->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $adjustments = EnrolmentAdjustment::query()->whereIn('enrolment_id', $ids)->whereNotNull('seance_id')->get()
            ->keyBy(fn (EnrolmentAdjustment $a) => $a->enrolment_id.'|'.$a->seance_id);
        $addedIds = $adjustments->where('kind', EnrolmentAdjustment::ADDED_SEANCE)->pluck('seance_id')->unique()->values()->all();
        $seances = Seance::query()
            ->where(fn ($q) => $q->whereIn('training_session_id', $enrolments->pluck('training_session_id')->unique()->values()->all())->orWhereIn('id', $addedIds))
            ->when(! $withCancelled, fn ($q) => $q->where('status', Seance::PLANNED))
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')->get();
        $exempt = self::exemptPairs($seances, $ids);

        $out = [];
        foreach ($enrolments as $enrolment) {
            $entered = $enrolment->entered_on?->toDateString();
            $left = $enrolment->left_on?->toDateString();
            $list = [];
            foreach ($seances as $seance) {
                $key = $enrolment->id.'|'.$seance->id;
                $adjustment = $adjustments[$key] ?? null;
                $added = $adjustment?->kind === EnrolmentAdjustment::ADDED_SEANCE;
                $date = $seance->date->toDateString();
                if ((! $added && $seance->training_session_id !== $enrolment->training_session_id) || isset($exempt[$key])
                    || ($entered !== null && $date < $entered) || ($left !== null && $date > $left)) {
                    continue;
                }
                $hours = self::hoursOf($seance, $adjustment);
                $list[] = new PlannedSeance($seance, $hours['start'], $hours['end'], $hours['minutes'], $added);
            }
            $out[$enrolment->id] = $list;
        }

        return $out;
    }

    /**
     * Writes his computed planned hours (in the caller's transaction): with
     * adjustments, the base is kept (planned_hours_base, set at the first
     * one) and planned_hours computed; without any, planned_hours goes back
     * to the base and the base is emptied.
     */
    public static function refresh(Enrolment $enrolment): void
    {
        $has = EnrolmentAdjustment::query()->where('enrolment_id', $enrolment->id)->exists();
        if (! $has) {
            if ($enrolment->planned_hours_base !== null) {
                $enrolment->planned_hours = $enrolment->planned_hours_base;
                $enrolment->planned_hours_base = null;
                $enrolment->save();
            }

            return;
        }
        if ($enrolment->planned_hours_base === null) {
            $enrolment->planned_hours_base = $enrolment->planned_hours
                ?? TrainingSession::withTrashed()->findOrFail($enrolment->training_session_id)->planned_hours;
        }
        $enrolment->planned_hours = SessionModules::hoursDecimal(self::plannedMinutes($enrolment));
        if ($enrolment->isDirty()) {
            $enrolment->save();
        }
    }

    /**
     * A séance was moved, cancelled or deleted: the planned hours of the
     * trainees with an adjustment on it are computed again (id order).
     */
    public static function refreshForSeance(Seance $seance): void
    {
        $ids = EnrolmentAdjustment::query()->where('seance_id', $seance->id)->distinct()->orderBy('enrolment_id')->pluck('enrolment_id')->all();
        foreach (Enrolment::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $enrolment) {
            self::refresh($enrolment);
        }
    }

    private static function minutesOfDay(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    private static function exemptionOf(QueryBuilder $q, Seance $seance): void
    {
        $q->selectRaw('1')->from('enrolment_adjustments')
            ->whereColumn('enrolment_adjustments.enrolment_id', 'enrolments.id')
            ->where(fn (QueryBuilder $q) => $q->where(fn (QueryBuilder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_SEANCE)->where('seance_id', $seance->id))
                ->orWhere(fn (QueryBuilder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->where('session_module_id', $seance->session_module_id)));
    }
}
