<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceRows;
use App\Modules\Sessions\Public\RealisedHours;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the attendance screens read:
 *  - grid(): the trainees of a séance and their attendance - names only,
 *    never adaptation needs, notes, fundings nor prices (a trainer reads it);
 *  - session(): the « Présences » tab of a session for a Paris month: its
 *    months and closures, its séances with their counts, the monthly grid
 *    of its interval modules and the realised hours (RealisedHours).
 */
final class AttendanceRows
{
    /**
     * @return array{seance: array<string, mixed>, rows: list<array<string, mixed>>, statuses: list<array{value: string, label: string}>, can_edit: bool}
     */
    public static function grid(Seance $seance): array
    {
        $attendance = Attendance::query()->where('seance_id', $seance->id)->get()->keyBy('enrolment_id');
        $expected = Attendances::expected($seance)->pluck('id')->all();
        $enrolments = Enrolment::query()->whereKey([...$expected, ...$attendance->keys()->all()])->get(['id', 'person_id']);
        $names = self::names($enrolments);
        $closed = self::closed($seance->training_session_id, $seance->date);
        $module = SessionModule::withTrashed()->find($seance->session_module_id);

        $rows = $enrolments->map(function (Enrolment $e) use ($attendance, $expected, $names): array {
            $a = $attendance[$e->id] ?? null;

            return [
                'enrolment_id' => $e->id,
                'name' => $names[$e->id],
                'status' => $a?->status,
                'minutes' => $a?->minutes,
                'minutes_label' => $a === null ? null : SessionModules::label($a->minutes),
                'reason' => $a?->reason,
                'needs_review' => $a !== null && $a->needs_review,
                // Entered while expected, the dates of the trainee changed since: kept, shown.
                'eligible' => in_array($e->id, $expected, true),
            ];
        })->sortBy(fn (array $r) => mb_strtolower($r['name']).$r['enrolment_id'])->values()->all();

        return [
            'seance' => [
                'id' => $seance->id,
                'session_id' => $seance->training_session_id,
                'date' => $seance->date->toDateString(),
                'start' => $seance->start(),
                'end' => $seance->end(),
                'duration_minutes' => $seance->duration_minutes,
                'duration' => SessionModules::label($seance->duration_minutes),
                'module' => $module === null ? '-' : $module->title,
                'cancelled' => $seance->isCancelled(),
                'closed' => $closed,
            ],
            'rows' => $rows,
            'statuses' => Attendance::options(),
            'can_edit' => ! $seance->isCancelled() && ! $closed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function session(TrainingSession $session, User $user, ?string $month): array
    {
        $months = Months::ofSession($session);
        $today = Months::key(TrainingSession::today());
        $month = match (true) {
            is_string($month) && in_array($month, $months, true) => $month,
            in_array($today, $months, true) => $today,
            $today > $months[array_key_last($months)] => $months[array_key_last($months)],
            default => $months[0],
        };
        $first = Months::parse($month) ?? throw new \LogicException('Mois invalide.');
        $last = $first->endOfMonth();
        $closures = AttendanceClosure::query()->where('training_session_id', $session->id)->get()->keyBy(fn (AttendanceClosure $c) => Months::key($c->month));
        $closure = $closures[$month] ?? null;
        $userNames = User::query()->whereKey(array_filter([$closure?->closed_by, $closure?->reopened_by]))->pluck('name', 'id');
        $manage = Scopes::of($user, Permissions::ATTENDANCE_MANAGE) !== AccessScope::Trainer
            && SessionsVisibility::allows($user, Permissions::ATTENDANCE_MANAGE, $session);
        // A trainer opens, from the « Suivi », the attendance of his own séances (the séance endpoints check it too).
        $ownTrainerId = ! $manage && Scopes::of($user, Permissions::ATTENDANCE_MANAGE) === AccessScope::Trainer
            ? Trainers::forUser($user)?->id : null;

        // Trainees: every enrolment of the session that attends or has hours, loaded once.
        $enrolments = Enrolment::query()->where('training_session_id', $session->id)->get();
        $names = self::names($enrolments);

        // Séances of the month and their counts (expected trainees computed here, no query each).
        $seances = Seance::query()->where('training_session_id', $session->id)->whereBetween('date', [$first->toDateString(), $last->toDateString()])
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')->get();
        $counts = Attendance::query()->whereIn('seance_id', $seances->pluck('id')->all())
            ->selectRaw('seance_id, count(*) as recorded, count(*) filter (where needs_review) as review')->groupBy('seance_id')
            ->toBase()->get()->keyBy('seance_id');
        $modules = SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->orderBy('id')->get();
        $titles = SessionModule::withTrashed()->where('training_session_id', $session->id)->pluck('title', 'id');
        $trainers = SeanceRows::trainerLabels($seances->pluck('trainer_id')->filter()->unique()->values()->all());

        // Interval modules covering the month, and the hours of the month.
        $interval = $modules->filter(fn (SessionModule $m) => $m->dating_mode === DatingMode::Interval->value
            && in_array($month, MonthlyHours::monthsOf($m, $session), true))->values();
        $hours = MonthlyHour::query()->whereIn('session_module_id', $interval->pluck('id')->all())->where('month', $first->toDateString())->get();
        $byTrainee = $hours->groupBy('enrolment_id');
        $monthlyRows = $enrolments->filter(fn (Enrolment $e) => $byTrainee->has($e->id) || self::attendsMonth($e, $first))
            ->map(fn (Enrolment $e) => [
                'enrolment_id' => $e->id,
                'name' => $names[$e->id],
                'allowed' => self::attendsMonth($e, $first),
                'minutes' => (object) ($byTrainee[$e->id] ?? collect())->mapWithKeys(fn (MonthlyHour $h) => [$h->session_module_id => $h->minutes])->all(),
                'trainers' => (object) ($byTrainee[$e->id] ?? collect())->mapWithKeys(fn (MonthlyHour $h) => [$h->session_module_id => $h->trainer_id])->all(),
            ])->sortBy(fn (array $r) => mb_strtolower($r['name']).$r['enrolment_id'])->values()->all();

        $realised = array_map(fn (array $r) => [
            'enrolment_id' => $r['enrolment_id'],
            'name' => $names[$r['enrolment_id']] ?? 'Stagiaire',
            'planned_minutes' => $r['planned_minutes'],
            'planned' => SessionModules::label($r['planned_minutes']),
            'realised_minutes' => $r['realised_minutes'],
            'realised' => SessionModules::label($r['realised_minutes']),
        ], RealisedHours::forSession($session->id, $month));
        usort($realised, fn (array $a, array $b) => strcmp(mb_strtolower($a['name']).$a['enrolment_id'], mb_strtolower($b['name']).$b['enrolment_id']));
        // The validated half-days of the month: a day is « validated » when every half its séances touch is, « partial » when some are.
        $validated = Signing\SignSlot::query()->where('training_session_id', $session->id)
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()])
            ->where('status', Signing\SignSlot::VALIDATED)->get(['date', 'half'])
            ->mapWithKeys(fn (Signing\SignSlot $slot) => [$slot->date->toDateString().'|'.$slot->half => true]);

        return [
            'month' => $month,
            'months' => array_map(fn (string $m) => ['value' => $m, 'label' => Months::label($m), 'closed' => ($closures[$m] ?? null)?->isClosed() ?? false], $months),
            'closure' => [
                'closed' => $closure?->isClosed() ?? false,
                'closed_at' => $closure?->closed_at?->toIso8601String(),
                'closed_by' => $closure?->closed_by === null ? null : ($userNames[$closure->closed_by] ?? null),
                'reopened_at' => $closure?->reopened_at?->toIso8601String(),
                'reopened_by' => $closure?->reopened_by === null ? null : ($userNames[$closure->reopened_by] ?? null),
                'reopen_reason' => $closure?->reopen_reason,
            ],
            'can' => [
                'manage' => $manage,
                'close' => SessionsVisibility::allows($user, Permissions::ATTENDANCE_CLOSE, $session),
            ],
            'seances' => $seances->map(fn (Seance $s) => [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'start' => $s->start(),
                'end' => $s->end(),
                'duration' => SessionModules::label($s->duration_minutes),
                'module' => $titles[$s->session_module_id] ?? '-',
                'trainer' => $s->trainer_id === null ? null : ($trainers[$s->trainer_id] ?? null),
                'cancelled' => $s->isCancelled(),
                'can_record' => $manage || ($ownTrainerId !== null && $s->trainer_id === $ownTrainerId),
                'recorded' => (int) ($counts[$s->id]->recorded ?? 0),
                'needs_review' => (int) ($counts[$s->id]->review ?? 0),
                'expected' => $enrolments->filter(fn (Enrolment $e) => self::attendsDay($e, $s->date))->count(),
                'signing' => self::signing($s, $seances, $validated),
            ])->values()->all(),
            'monthly' => [
                'modules' => $interval->map(fn (SessionModule $m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'planned' => $m->planned_minutes === null ? SessionModules::UNDEFINED : SessionModules::label($m->planned_minutes),
                ])->values()->all(),
                'rows' => $monthlyRows,
                'trainers' => $interval->isEmpty() || ! $manage ? [] : array_map(
                    fn (array $o) => ['value' => $o['value'], 'label' => $o['label']],
                    SeanceRows::options($session)['trainers'],
                ),
            ],
            'realised' => $realised,
            'slots' => Signing\SlotRows::forMonth($session->id, $first),
            'signed_sheets' => Signing\SignedSheetController::list($session->id),
            // Catch-ups to confirm: attendance managers only (never « Ses séances »).
            'catch_ups' => $manage ? Signing\SlotRows::pendingCatchUps($session->id, $first) : [],
        ];
    }

    /**
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, string> enrolment id => « Léa Durand »
     */
    private static function names(Collection $enrolments): array
    {
        $labels = app(Fiches::class)->personLabels($enrolments->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());

        return $enrolments->mapWithKeys(fn (Enrolment $e) => [$e->id => $labels[$e->person_id]['label'] ?? 'Stagiaire'])->all();
    }

    private static function attendsDay(Enrolment $e, CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return in_array($e->status->value, Attendances::ATTENDING, true)
            && ($e->entered_on === null || $e->entered_on->toDateString() <= $date)
            && ($e->left_on === null || $e->left_on->toDateString() >= $date);
    }

    private static function attendsMonth(Enrolment $e, CarbonImmutable $first): bool
    {
        return in_array($e->status->value, Attendances::ATTENDING, true)
            && ($e->entered_on === null || Months::of($e->entered_on->toDateString())->lte($first))
            && ($e->left_on === null || Months::of($e->left_on->toDateString())->gte($first));
    }

    private static function closed(string $sessionId, CarbonImmutable $date): bool
    {
        return AttendanceClosure::query()->where('training_session_id', $sessionId)
            ->where('month', Months::of($date->toDateString())->toDateString())->whereNotNull('closed_at')->exists();
    }

    /**
     * The day of the séance: « validated » when every half-day touched by its
     * live séances that day is validated, « partial » when some are, null
     * otherwise (a morning séance and an afternoon séance share their day).
     *
     * @param  Collection<int, Seance>  $seances  the month's séances
     * @param  Collection<string, bool>  $validated  keys « date|half »
     */
    private static function signing(Seance $seance, Collection $seances, Collection $validated): ?string
    {
        $date = $seance->date->toDateString();
        $halves = $seances->filter(fn (Seance $s) => $s->date->toDateString() === $date && ! $s->isCancelled())
            ->flatMap(fn (Seance $s) => Signing\Halves::of($s->start(), $s->end()))->unique()->values()->all();
        $done = count(array_filter($halves, fn (string $half) => $validated->has($date.'|'.$half)));

        return match (true) {
            $done === 0 => null,
            $done === count($halves) => 'validated',
            default => 'partial',
        };
    }
}
