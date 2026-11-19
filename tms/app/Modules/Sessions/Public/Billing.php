<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\AbandonRule;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Public service of what the invoicing (Finance, 0.9) reads of a dossier:
 * plain arrays, amounts in integer cents, minutes as integers, Paris days
 * and months (« 2026-09 »); Finance never names a table of Sessions.
 *
 *  - dossier(): the dossier, its lines, the enrolments sharing its price
 *    (enrolled, confirmed, abandoned: never waitlisted nor cancelled), its
 *    fundings (refused ones included, with their state) and the sessions of
 *    its enrolments;
 *  - hours(): realised, planned and unjustified absent minutes per
 *    enrolment, and per session of a line priced for the group (see the
 *    method); a fixed number of queries whatever the number of trainees;
 *  - allows(): whether a right (and its scope) reaches one dossier;
 *  - dossiersFor(): the dossiers a user reaches (sessions.dossiers.view and
 *    its scope), for the lists of the invoicing;
 *  - lockDossier(): the row lock of a dossier, taken first by the drafts
 *    and the validation of its invoices;
 *  - dossiersOfTrainees(): the dossiers of people (their anonymisation).
 *
 * @phpstan-type EnrolmentHours array{enrolment_id: string, session_id: string, planned_minutes: int, realised_minutes: int, absent_minutes: int, all_months_closed: bool, closed_months: list<string>}
 * @phpstan-type SessionHours array{session_id: string, planned_minutes: int, realised_minutes: int, all_months_closed: bool, closed_months: list<string>}
 */
final class Billing
{
    /**
     * The dossier as the invoicing reads it, null when unknown (a dossier in
     * the trash is still given: its invoices stay readable, « trashed »).
     *
     * @return array<string, mixed>|null
     */
    public static function dossier(string $dossierId): ?array
    {
        if (! Str::isUuid($dossierId)) {
            return null;
        }
        $dossier = Dossier::withTrashed()->with('lines')->find($dossierId);
        if ($dossier === null) {
            return null;
        }
        $enrolments = self::enrolments($dossier->id);
        $forceMajeure = ReferenceItem::withTrashed()->whereKey($enrolments->pluck('abandon_reason_id')->filter()->unique()->values()->all())
            ->get(['id', 'meta'])->filter(fn (ReferenceItem $i) => ($i->meta['force_majeure'] ?? false) === true)->pluck('id')->all();
        $fundings = Funding::query()->where('dossier_id', $dossier->id)->orderBy('created_at')->orderBy('id')->get();
        $externalTypes = ReferenceItem::withTrashed()->whereKey($fundings->pluck('funding_type_id')->unique()->values()->all())
            ->get(['id', 'meta'])->filter(fn (ReferenceItem $i) => ($i->meta['external_billing'] ?? false) === true)->pluck('id')->all();
        $sessions = TrainingSession::withTrashed()->whereKey($enrolments->pluck('training_session_id')->unique()->values()->all())
            ->orderBy('starts_on')->orderBy('number')->get();
        $trainings = app(Trainings::class)->findMany($sessions->pluck('training_id')->unique()->values()->all());
        $order = $sessions->pluck('id')->flip();
        $rule = AbandonRule::forDossier($dossier);
        $today = Dossier::today();

        return [
            'id' => $dossier->id,
            'number' => $dossier->number,
            'company_id' => $dossier->company_id,
            'das_id' => $dossier->das_id,
            'responsible_user_id' => $dossier->responsible_user_id,
            'created_by' => $dossier->created_by,
            'status' => $dossier->effectiveStatus($today)->value,
            'trashed' => $dossier->trashed(),
            'contractor' => ['type' => $dossier->accountType(), 'id' => $dossier->accountId()],
            // The particulier's withdrawal delay runs from it (Finance 0.9.1).
            'convention_signed_on' => $dossier->convention_signed_on?->toDateString(),
            'price_cents' => $dossier->priceCents(),
            'abandon' => ['mode' => $rule['mode'], 'dedit_percent' => $rule['dedit_percent']],
            'lines' => $dossier->lines->map(fn (DossierLine $l) => [
                'id' => $l->id,
                'kind' => $l->kind,
                'pricing_mode' => $l->pricing_mode,
                'label' => $l->label,
                'total_cents' => Amounts::cents((string) $l->total_ht),
                'unit' => $l->unit,
                'quantity' => (string) $l->quantity,
                'unit_price_cents' => Amounts::cents((string) $l->unit_price),
                'discount_percent' => (string) $l->discount_percent,
                'das_id' => $l->das_id,
                'training_id' => $l->training_id,
                'session_ids' => $enrolments->where('dossier_line_id', $l->id)->pluck('training_session_id')->unique()
                    ->sortBy(fn (string $id) => $order[$id] ?? PHP_INT_MAX)->values()->all(),
            ])->values()->all(),
            'enrolments' => $enrolments->map(fn (Enrolment $e) => [
                'id' => $e->id,
                'line_id' => $e->dossier_line_id,
                'person_id' => $e->person_id,
                'status' => $e->status->value,
                'price_share_cents' => $e->price_share === null ? null : Amounts::cents((string) $e->price_share),
                'session_id' => $e->training_session_id,
                'entered_on' => $e->entered_on?->toDateString(),
                'left_on' => $e->left_on?->toDateString(),
                'abandoned' => $e->status === EnrolmentStatus::Abandoned,
                // A force majeure abandon never costs a dédit (AbandonRule).
                'force_majeure' => $e->abandon_reason_id !== null && in_array($e->abandon_reason_id, $forceMajeure, true),
            ])->values()->all(),
            'fundings' => $fundings->map(fn (Funding $f) => [
                'id' => $f->id,
                'funder' => ['type' => $f->funderType(), 'id' => $f->funderId()],
                'type_id' => $f->funding_type_id,
                'external_billing' => in_array($f->funding_type_id, $externalTypes, true),
                'amount_cents' => Amounts::cents((string) $f->amount),
                'status' => $f->status->value,
                'agreement_number' => $f->agreement_number,
                'pays_directly' => $f->pays_directly,
                'line_id' => $f->dossier_line_id,
                'enrolment_id' => $f->enrolment_id,
            ])->values()->all(),
            'sessions' => $sessions->map(fn (TrainingSession $s) => [
                'id' => $s->id,
                'starts_on' => $s->starts_on->toDateString(),
                'ends_on' => $s->ends_on->toDateString(),
                'status' => $s->effectiveStatus($today)->value,
                'label' => $s->label(isset($trainings[$s->training_id]) ? $trainings[$s->training_id]->title : null),
            ])->values()->all(),
        ];
    }

    /**
     * Minutes of the dossier, the rules of RealisedHours (a cancelled séance
     * never counts; only within the trainee's entry and leaving days, months
     * for interval hours):
     *
     *  - per enrolment sharing the price: planned = the enrolment's planned
     *    minutes (its planned hours, else the session's modules); realised =
     *    attendance minutes + interval monthly hours; absent = unjustified
     *    absences of past séances (« absent »: the séance's minutes,
     *    « partiel »: what is missing; « absence justifiée », present or
     *    nothing entered: 0); interval modules never give absences;
     *  - per session of a line priced for the group: planned = its modules'
     *    planned minutes; realised = the minutes of its planned séances held
     *    (date before today, Paris, whatever was entered as attendance) +
     *    for each interval module, month by month, the most hours entered for
     *    one of the dossier's trainees (the group trains together);
     *  - $untilMonth (« 2026-10 »): only the closed months of each session up
     *    to it count; null: everything entered so far;
     *  - all_months_closed: every month of the session's dates is closed;
     *    closed_months: the session's closed months.
     *
     * @return array{enrolments: list<EnrolmentHours>, sessions: list<SessionHours>}
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function hours(string $dossierId, ?string $untilMonth = null): array
    {
        $until = $untilMonth === null ? null : (Months::parse($untilMonth) ?? throw new InvalidArgumentException("Mois invalide : {$untilMonth}"));
        if (! Str::isUuid($dossierId)) {
            return ['enrolments' => [], 'sessions' => []];
        }
        $enrolments = self::enrolments($dossierId);
        $ids = $enrolments->pluck('id')->all();
        $sessionIds = $enrolments->pluck('training_session_id')->unique()->values()->all();
        $groupLines = DossierLine::query()->where('dossier_id', $dossierId)->where('pricing_mode', DossierLine::GROUP)->pluck('id')->all();
        $groupSessionIds = $enrolments->whereIn('dossier_line_id', $groupLines)->pluck('training_session_id')->unique()->values()->all();
        $today = Dossier::today()->toDateString();

        $sessions = TrainingSession::withTrashed()->whereKey($sessionIds)->orderBy('starts_on')->orderBy('number')->get(['id', 'starts_on', 'ends_on']);
        $modules = SessionModule::query()->whereIn('training_session_id', $sessionIds)
            ->get(['id', 'training_session_id', 'planned_minutes', 'dating_mode'])->groupBy('training_session_id');
        /** @var array<string, list<string>> $closed */
        $closed = AttendanceClosure::query()->whereIn('training_session_id', $sessionIds)->whereNotNull('closed_at')
            ->orderBy('month')->get(['training_session_id', 'month'])->groupBy('training_session_id')
            ->map(fn ($rows) => $rows->map(fn (AttendanceClosure $c) => Months::key($c->month))->values()->all())->all();

        // Attendance per trainee and month: realised minutes and unjustified absences of past séances.
        $attendance = Attendance::query()
            ->join('seances', 'seances.id', '=', 'attendances.seance_id')
            ->join('enrolments', 'enrolments.id', '=', 'attendances.enrolment_id')
            ->whereIn('attendances.enrolment_id', $ids)
            ->whereNull('seances.deleted_at')
            ->where('seances.status', '<>', Seance::CANCELLED)
            ->where(fn ($q) => $q->whereNull('enrolments.entered_on')->orWhereColumn('seances.date', '>=', 'enrolments.entered_on'))
            ->where(fn ($q) => $q->whereNull('enrolments.left_on')->orWhereColumn('seances.date', '<=', 'enrolments.left_on'))
            ->groupByRaw("attendances.enrolment_id, to_char(seances.date, 'YYYY-MM')")
            ->selectRaw("attendances.enrolment_id as enrolment_id, to_char(seances.date, 'YYYY-MM') as month, sum(attendances.minutes)::bigint as realised"
                .', sum(case when seances.date < ?::date and attendances.status = ? then seances.duration_minutes'
                .' when seances.date < ?::date and attendances.status = ? then greatest(seances.duration_minutes - attendances.minutes, 0)'
                .' else 0 end)::bigint as absent', [$today, Attendance::ABSENT, $today, Attendance::PARTIAL])
            ->toBase()->get();

        // Interval hours per trainee, module and month, within his months.
        $monthly = MonthlyHour::query()
            ->join('enrolments', 'enrolments.id', '=', 'monthly_hours.enrolment_id')
            ->whereIn('monthly_hours.enrolment_id', $ids)
            ->where(fn ($q) => $q->whereNull('enrolments.entered_on')->orWhereRaw("monthly_hours.month >= date_trunc('month', enrolments.entered_on)::date"))
            ->where(fn ($q) => $q->whereNull('enrolments.left_on')->orWhereRaw("monthly_hours.month <= date_trunc('month', enrolments.left_on)::date"))
            ->groupByRaw("monthly_hours.enrolment_id, monthly_hours.session_module_id, to_char(monthly_hours.month, 'YYYY-MM')")
            ->selectRaw("monthly_hours.enrolment_id as enrolment_id, monthly_hours.session_module_id as module_id, to_char(monthly_hours.month, 'YYYY-MM') as month"
                .', sum(monthly_hours.minutes)::bigint as minutes')
            ->toBase()->get();

        // Séances held of the group sessions, per month.
        $held = Seance::query()->whereIn('training_session_id', $groupSessionIds)
            ->where('status', Seance::PLANNED)->where('date', '<', $today)
            ->groupByRaw("training_session_id, to_char(date, 'YYYY-MM')")
            ->selectRaw("training_session_id, to_char(date, 'YYYY-MM') as month, sum(duration_minutes)::bigint as minutes")
            ->toBase()->get();

        $sessionOf = $enrolments->pluck('training_session_id', 'id')->all();
        $counts = function (string $sessionId, string $month) use ($until, $closed): bool {
            return $until === null || ($month <= Months::key($until) && in_array($month, $closed[$sessionId] ?? [], true));
        };

        $realised = [];
        $absent = [];
        foreach ($attendance as $row) {
            if ($counts($sessionOf[$row->enrolment_id], $row->month)) {
                $realised[$row->enrolment_id] = ($realised[$row->enrolment_id] ?? 0) + (int) $row->realised;
                $absent[$row->enrolment_id] = ($absent[$row->enrolment_id] ?? 0) + (int) $row->absent;
            }
        }
        $groupInterval = [];
        foreach ($monthly as $row) {
            $sessionId = $sessionOf[$row->enrolment_id];
            if (! $counts($sessionId, $row->month)) {
                continue;
            }
            $realised[$row->enrolment_id] = ($realised[$row->enrolment_id] ?? 0) + (int) $row->minutes;
            $key = $sessionId.'|'.$row->module_id.'|'.$row->month;
            $groupInterval[$key] = max($groupInterval[$key] ?? 0, (int) $row->minutes);
        }

        $planned = fn (string $sessionId): int => (int) ($modules[$sessionId] ?? new Collection)->sum(fn (SessionModule $m) => $m->planned_minutes ?? 0);
        $sessionMonths = $sessions->mapWithKeys(fn (TrainingSession $s) => [$s->id => Months::between($s->starts_on, $s->ends_on)])->all();
        $allClosed = fn (string $sessionId): bool => isset($sessionMonths[$sessionId])
            && array_diff($sessionMonths[$sessionId], $closed[$sessionId] ?? []) === [];

        $byEnrolment = $enrolments->map(fn (Enrolment $e) => [
            'enrolment_id' => $e->id,
            'session_id' => $e->training_session_id,
            'planned_minutes' => SessionModules::minutesOf($e->planned_hours) ?? $planned($e->training_session_id),
            'realised_minutes' => $realised[$e->id] ?? 0,
            'absent_minutes' => $absent[$e->id] ?? 0,
            'all_months_closed' => $allClosed($e->training_session_id),
            'closed_months' => $closed[$e->training_session_id] ?? [],
        ])->values()->all();

        $bySession = [];
        foreach ($sessions->whereIn('id', $groupSessionIds) as $session) {
            $minutes = 0;
            foreach ($held as $row) {
                if ($row->training_session_id === $session->id && $counts($session->id, $row->month)) {
                    $minutes += (int) $row->minutes;
                }
            }
            $intervalModules = ($modules[$session->id] ?? new Collection)
                ->filter(fn (SessionModule $m) => $m->dating_mode === DatingMode::Interval->value)->pluck('id')->all();
            foreach ($groupInterval as $key => $value) {
                [$sessionId, $moduleId] = explode('|', $key);
                if ($sessionId === $session->id && in_array($moduleId, $intervalModules, true)) {
                    $minutes += $value;
                }
            }
            $bySession[] = [
                'session_id' => $session->id,
                'planned_minutes' => $planned($session->id),
                'realised_minutes' => $minutes,
                'all_months_closed' => $allClosed($session->id),
                'closed_months' => $closed[$session->id] ?? [],
            ];
        }

        return ['enrolments' => $byEnrolment, 'sessions' => $bySession];
    }

    /**
     * The dossiers (out of the trash) $user reaches by $permission and its
     * scope (sessions.dossiers.view by default; Finance passes its own
     * rights, whose own / das / all are read on the dossier), as a query of
     * their ids, to use as a subquery (whereIn('dossier_id',
     * Billing::dossiersFor(...))). Filters: company_id, das_id,
     * responsible_user_id; started_by (a Paris day): only the dossiers
     * neither cancelled nor settled with a trainee sharing the price in a
     * session (not in the trash) started on or before that day — those
     * that may have something to invoice; with_trashed: dossiers in the
     * trash too (their validated invoices stay listed).
     *
     * @param  array{company_id?: string|null, das_id?: string|null, responsible_user_id?: string|null, started_by?: string|null, with_trashed?: bool}  $filters
     * @return Builder<Dossier>
     */
    public static function dossiersFor(User $user, array $filters = [], string $permission = Permissions::DOSSIERS_VIEW): Builder
    {
        return SessionsVisibility::dossiers(($filters['with_trashed'] ?? false) ? Dossier::withTrashed() : Dossier::query(), $user, $permission)
            ->when($filters['company_id'] ?? null, fn (Builder $q, string $id) => $q->where('dossiers.company_id', $id))
            ->when($filters['das_id'] ?? null, fn (Builder $q, string $id) => $q->where('dossiers.das_id', $id))
            ->when($filters['responsible_user_id'] ?? null, fn (Builder $q, string $id) => $q->where('dossiers.responsible_user_id', $id))
            ->when($filters['started_by'] ?? null, fn (Builder $q, string $day) => $q
                ->whereNotIn('dossiers.status', [DossierStatus::Cancelled->value, DossierStatus::Settled->value])
                ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('enrolments')
                    ->join('training_sessions', 'training_sessions.id', '=', 'enrolments.training_session_id')
                    ->whereColumn('enrolments.dossier_id', 'dossiers.id')
                    ->whereIn('enrolments.status', EnrolmentStatus::values(EnrolmentStatus::SHARING_THE_PRICE))
                    ->whereNull('training_sessions.deleted_at')
                    ->where('training_sessions.starts_on', '<=', $day)))
            ->select('dossiers.id');
    }

    /**
     * Number of these dossiers keyed by id, trashed ones included (the lists
     * of invoices); unknown ids are left out.
     *
     * @param  list<string>  $ids
     * @return array<string, array{number: string, trashed: bool}>
     */
    public static function numbers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id) => Str::isUuid($id))));
        if ($ids === []) {
            return [];
        }

        return Dossier::withTrashed()->whereKey($ids)->get(['id', 'number', 'deleted_at'])
            ->mapWithKeys(fn (Dossier $d) => [$d->id => ['number' => (string) $d->number, 'trashed' => $d->trashed()]])->all();
    }

    /**
     * $permission (its scope read on the dossier: own, das, all) reaches the
     * dossier $dossierId; false for an unknown id. $withTrashed: a dossier in
     * the trash counts too (reading its invoices), else it is never reached.
     */
    public static function allows(User $user, string $permission, string $dossierId, bool $withTrashed = false): bool
    {
        if (! Str::isUuid($dossierId)) {
            return false;
        }
        $dossier = ($withTrashed ? Dossier::withTrashed() : Dossier::query())->find($dossierId);

        return $dossier !== null && SessionsVisibility::allows($user, $permission, $dossier);
    }

    /**
     * Locks the row of the dossier (in the trash too) for the caller's
     * transaction: the invoicing serialises its drafts and validations of a
     * dossier on it, and with the writers of Sessions that lock it too
     * (fundings, enrolments, transitions). An unknown id locks nothing.
     *
     * @throws LogicException outside a transaction (the lock would be released at once)
     */
    public static function lockDossier(string $dossierId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Billing::lockDossier() doit s\'exécuter dans une transaction.');
        }
        if (Str::isUuid($dossierId)) {
            Dossier::withTrashed()->whereKey($dossierId)->lockForUpdate()->first(['id']);
        }
    }

    /**
     * Ids of the dossiers (in the trash too) where one of these people is
     * or was enrolled, whatever the state of the enrolment (the invoicing
     * names the trainees on a funder's invoice).
     *
     * @param  list<string>  $personIds
     * @return list<string>
     */
    public static function dossiersOfTrainees(array $personIds): array
    {
        $personIds = array_values(array_filter($personIds, fn (string $id) => Str::isUuid($id)));
        if ($personIds === []) {
            return [];
        }

        return Enrolment::query()->whereIn('person_id', $personIds)->whereNotNull('dossier_id')
            ->distinct()->pluck('dossier_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * The enrolments sharing the price of the dossier, in order of creation.
     *
     * @return Collection<int, Enrolment>
     */
    private static function enrolments(string $dossierId): Collection
    {
        return Enrolment::query()->where('dossier_id', $dossierId)
            ->whereIn('status', EnrolmentStatus::values(EnrolmentStatus::SHARING_THE_PRICE))
            ->orderBy('created_at')->orderBy('id')->get();
    }
}
