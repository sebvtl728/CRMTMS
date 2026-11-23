<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\SessionLocation;
use App\Modules\Sessions\Sessions\SessionPlace;
use App\Modules\Sessions\Sessions\TrainingSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the training documents (module Documents, 0.10) read of a dossier:
 * Billing::dossier() plus the contact, the places, dates and planned
 * minutes of its sessions and of each trainee (enrolled, confirmed or left:
 * never the waiting list nor a cancelled enrolment). Also moves the dossier
 * along when its convention is sent or signed.
 */
final class Paperwork
{
    public function __construct(private readonly DossierTransitions $transitions) {}

    /**
     * @return array<string, mixed>|null
     */
    public static function dossier(string $dossierId): ?array
    {
        $billing = Billing::dossier($dossierId);
        if ($billing === null) {
            return null;
        }
        $dossier = Dossier::withTrashed()->findOrFail($dossierId);
        $sessions = TrainingSession::withTrashed()->whereKey(array_column($billing['sessions'], 'id'))->get()->keyBy('id');
        $enrolments = Enrolment::query()->whereKey(array_column($billing['enrolments'], 'id'))->get()->keyBy('id');

        return [
            ...$billing,
            'contact_person_id' => $dossier->contact_person_id,
            'sessions' => array_map(function (array $s) use ($sessions): array {
                $session = $sessions[$s['id']];

                return [
                    ...$s,
                    'training_id' => $session->training_id,
                    'planned_minutes' => self::minutes($session->planned_hours),
                    'place' => self::place($session),
                ];
            }, $billing['sessions']),
            'trainees' => array_values(array_map(function (array $e) use ($enrolments, $sessions): array {
                $enrolment = $enrolments[$e['id']];

                return [
                    'enrolment_id' => $e['id'],
                    'person_id' => $e['person_id'],
                    'session_id' => $e['session_id'],
                    'entered_on' => $e['entered_on'],
                    'left_on' => $e['left_on'],
                    'status' => $e['status'],
                    'planned_minutes' => self::minutes($enrolment->planned_hours ?? $sessions[$e['session_id']]->planned_hours),
                ];
            }, $billing['enrolments'])),
        ];
    }

    /**
     * One trainee for the per-trainee documents (convocation, attestation,
     * certificate): the session (cancelled or not), the planned séances
     * (date order), the planned and realised minutes (RealisedHours).
     * $completeOn (the last day, at 13:30, the automatic certificates): the
     * live séances of that day not recorded yet for the trainee count as
     * realised (its afternoon, and a morning not entered yet).
     *
     * @return array<string, mixed>|null
     */
    public static function enrolment(string $enrolmentId, ?string $completeOn = null): ?array
    {
        $enrolment = Str::isUuid($enrolmentId) ? Enrolment::query()->find($enrolmentId) : null;
        if ($enrolment === null) {
            return null;
        }
        $session = TrainingSession::withTrashed()->findOrFail($enrolment->training_session_id);
        $hours = RealisedHours::forEnrolment($enrolment->id);
        $seances = Seance::query()->where('training_session_id', $session->id)->where('status', 'planned')
            ->orderBy('date')->orderBy('starts_at')->get();
        if ($completeOn !== null) {
            $recorded = Attendance::query()->where('enrolment_id', $enrolment->id)->pluck('seance_id')->map(fn ($id) => (string) $id)->all();
            $hours['realised_minutes'] = (int) $hours['realised_minutes'] + (int) $seances
                ->filter(fn (Seance $s) => $s->date->toDateString() === $completeOn && ! in_array($s->id, $recorded, true))->sum('duration_minutes');
        }

        return [
            'id' => $enrolment->id,
            'status' => $enrolment->status->value,
            'person_id' => $enrolment->person_id,
            'dossier_id' => $enrolment->dossier_id,
            'entered_on' => $enrolment->entered_on?->toDateString(),
            'left_on' => $enrolment->left_on?->toDateString(),
            'planned_minutes' => self::minutes($enrolment->planned_hours ?? $session->planned_hours),
            'realised_minutes' => (int) $hours['realised_minutes'],
            'session' => [
                'id' => $session->id,
                'label' => $session->label(app(Trainings::class)->findMany([$session->training_id])[$session->training_id]->title ?? null),
                'training_id' => $session->training_id,
                'starts_on' => $session->starts_on->toDateString(),
                'ends_on' => $session->ends_on->toDateString(),
                'place' => self::place($session),
                'cancelled' => $session->isCancelled(),
            ],
            'schedule' => $seances->map(fn (Seance $s) => [
                'date' => $s->date->toDateString(),
                'starts_at' => substr($s->starts_at, 0, 5),
                'ends_at' => substr($s->ends_at, 0, 5),
                'place' => $s->place_text,
            ])->values()->all(),
        ];
    }

    /**
     * The confirmed sessions (stored state, never cancelled nor trashed)
     * whose first live séance falls between $from and $to (Paris days,
     * inclusive): the automatic convocations (2026-11-22).
     *
     * @return list<array{session_id: string, starts_on: string}>
     */
    public static function confirmedSessionsStartingBetween(string $from, string $to): array
    {
        return Seance::query()->where('status', '!=', Seance::CANCELLED)
            ->whereIn('training_session_id', TrainingSession::query()->where('status', 'confirmed')->select('id'))
            ->groupBy('training_session_id')->selectRaw('training_session_id, min(date) as first_day')
            ->havingRaw('min(date) between ? and ?', [$from, $to])->orderBy('first_day')->toBase()->get()
            ->map(fn ($row) => ['session_id' => (string) $row->training_session_id, 'starts_on' => CarbonImmutable::parse($row->first_day)->toDateString()])
            ->values()->all();
    }

    /**
     * The live sessions (never cancelled nor trashed) whose attendance is
     * final: every month from their first to their last live séance closed,
     * the last closing since $since (the automatic certificates, 2026-11-22).
     * Starts from the few closures since $since.
     *
     * @return list<string>
     */
    public static function sessionsFinallyClosedSince(CarbonImmutable $since): array
    {
        $candidates = AttendanceClosure::query()->whereNotNull('closed_at')->where('closed_at', '>=', $since)
            ->distinct()->pluck('training_session_id')->map(fn ($id) => (string) $id)->all();
        if ($candidates === []) {
            return [];
        }
        $spans = Seance::query()->where('status', '!=', Seance::CANCELLED)->whereIn('training_session_id', $candidates)
            ->whereIn('training_session_id', TrainingSession::query()->where('status', '!=', 'cancelled')->select('id'))
            ->groupBy('training_session_id')->selectRaw('training_session_id, min(date) as first_day, max(date) as last_day')->toBase()->get();
        $out = [];
        foreach ($spans as $span) {
            $months = Months::between(CarbonImmutable::parse((string) $span->first_day), CarbonImmutable::parse((string) $span->last_day));
            $closed = AttendanceClosure::query()->where('training_session_id', $span->training_session_id)->whereNotNull('closed_at')
                ->whereIn('month', array_map(fn (string $m) => $m.'-01', $months))->count();
            if ($closed === count($months)) {
                $out[] = (string) $span->training_session_id;
            }
        }

        return $out;
    }

    /**
     * The stored state and first live séance of a session (the automatic
     * convocations' notice), null when unknown or without séance.
     *
     * @return array{status: string, first_day: string}|null
     */
    public static function sessionStart(string $sessionId): ?array
    {
        $session = TrainingSession::query()->find($sessionId);
        $first = $session === null ? null : Seance::query()->where('training_session_id', $sessionId)->where('status', '!=', Seance::CANCELLED)->min('date');

        return $first === null ? null : ['status' => $session->status->value, 'first_day' => CarbonImmutable::parse($first)->toDateString()];
    }

    /**
     * The trainees to convene automatically (2026-11-22): enrolled or
     * confirmed (never waitlisted, abandoned nor cancelled), not left before
     * the first day $startsOn, in order of entry.
     *
     * @return list<array{id: string, person_id: string}>
     */
    public static function convocable(string $sessionId, string $startsOn): array
    {
        return Enrolment::query()->where('training_session_id', $sessionId)
            ->whereIn('status', EnrolmentStatus::values([EnrolmentStatus::Enrolled, EnrolmentStatus::Confirmed]))
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $startsOn))
            ->orderByRaw('entered_on asc nulls first')->orderBy('created_at')->orderBy('id')->get(['id', 'person_id'])
            ->map(fn (Enrolment $e) => ['id' => (string) $e->id, 'person_id' => (string) $e->person_id])->values()->all();
    }

    /**
     * The live sessions (never cancelled nor trashed) ending on $date with a
     * live séance that day (the certificates of the last day, at 13:30).
     *
     * @return list<string>
     */
    public static function sessionsEndingWithSeanceOn(string $date): array
    {
        return Seance::query()->where('status', '!=', Seance::CANCELLED)->where('date', $date)
            ->whereIn('training_session_id', TrainingSession::query()->where('status', '!=', 'cancelled')->where('ends_on', $date)->select('id'))
            ->distinct()->pluck('training_session_id')->map(fn ($id) => (string) $id)->all();
    }

    /** Whether the trainee was entered absent at a séance of $date. */
    public static function absentOn(string $enrolmentId, string $date): bool
    {
        return Attendance::query()->where('enrolment_id', $enrolmentId)->where('status', 'absent')
            ->whereIn('seance_id', Seance::query()->where('date', $date)->select('id'))->exists();
    }

    /**
     * The trainees of a session who receive documents (enrolled, confirmed,
     * left), in order of entry.
     *
     * @return list<string>
     */
    public static function sessionEnrolments(string $sessionId): array
    {
        return Enrolment::query()->where('training_session_id', $sessionId)
            ->whereIn('status', EnrolmentStatus::values(EnrolmentStatus::SHARING_THE_PRICE))
            ->orderByRaw('entered_on asc nulls first')->orderBy('created_at')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * The trainees (sessionEnrolments) and last day of these sessions, for
     * the summary cards of the documents, in two queries.
     *
     * @param  list<string>  $sessionIds
     * @return array<string, array{ends_on: string, enrolment_ids: list<string>}>
     */
    public static function sessionsOverview(array $sessionIds): array
    {
        $enrolments = Enrolment::query()->whereIn('training_session_id', $sessionIds)
            ->whereIn('status', EnrolmentStatus::values(EnrolmentStatus::SHARING_THE_PRICE))->get(['id', 'training_session_id'])->groupBy('training_session_id');

        return TrainingSession::query()->whereKey($sessionIds)->get(['id', 'ends_on'])->mapWithKeys(fn (TrainingSession $s) => [$s->id => [
            'ends_on' => $s->ends_on->toDateString(),
            'enrolment_ids' => ($enrolments[$s->id] ?? collect())->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        ]])->all();
    }

    public static function allows(User $user, string $permission, string $dossierId): bool
    {
        return Billing::allows($user, $permission, $dossierId);
    }

    /** « Convention envoyée » from « Créé »; any later state stays as it is. */
    public function markConventionSent(string $dossierId, CarbonImmutable $on): void
    {
        $dossier = Dossier::query()->find($dossierId);
        if ($dossier !== null && $dossier->status === DossierStatus::Created) {
            $this->transitions->sendConvention($dossier, $on);
        }
    }

    /** « Convocations envoyées » from « Convention signée » only; any other state stays as it is. */
    public function markConvocationsSent(string $dossierId, CarbonImmutable $on): void
    {
        $dossier = Dossier::query()->find($dossierId);
        if ($dossier !== null && $dossier->status === DossierStatus::ConventionSigned) {
            $this->transitions->sendConvocations($dossier, $on);
        }
    }

    /** « Convention signée » from « Créé » (both steps) or « Convention envoyée »; later states stay. */
    public function markConventionSigned(string $dossierId, CarbonImmutable $on): void
    {
        $dossier = Dossier::query()->find($dossierId);
        if ($dossier === null) {
            return;
        }
        if ($dossier->status === DossierStatus::Created) {
            $dossier = $this->transitions->sendConvention($dossier, $on);
        }
        if ($dossier->status === DossierStatus::ConventionSent) {
            $this->transitions->signConvention($dossier, $on);
        }
    }

    private static function minutes(?string $hours): ?int
    {
        return $hours === null || ! is_numeric($hours) ? null : BigDecimal::of($hours)->multipliedBy(60)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /** The address of the session (SessionPlace); « Distanciel » or « Chez le client » when there is none to print. */
    private static function place(TrainingSession $session): ?string
    {
        $text = trim((string) $session->location_text);

        return match ($session->location_kind) {
            SessionLocation::Remote->value => SessionLocation::Remote->label(),
            SessionLocation::Client->value => $text !== '' ? $text : SessionLocation::Client->label(),
            default => SessionPlace::of($session),
        };
    }
}
