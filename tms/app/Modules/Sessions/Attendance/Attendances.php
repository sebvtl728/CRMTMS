<?php

namespace App\Modules\Sessions\Attendance;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Planning\Events\SeanceChanged;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attendance of the séances (spec § 4.7): one row per séance and trainee,
 * in whole minutes - present = the séance's duration, partial 0 < m <
 * duration (typed), absent / excused 0. « Tous présents » marks present the
 * expected trainees not entered yet. Each write locks the séance (as
 * Seances does) and holds the session row shared (Closures locks it to
 * close a month): a cancelled séance (409) or a closed month (409) is never
 * written.
 *
 * Expected at a séance: the trainees enrolled, confirmed or abandoned, from
 * their entry day (late entries) up to their leaving day (abandon), never
 * after; waitlisted and cancelled ones never.
 *
 * A séance moved to other hours puts its attendance « à revoir »: present
 * recomputed at the new duration, partial above it capped; a cancelled
 * séance takes its attendance to 0 minute (kept, journaled): review(), run by Seances in its transaction.
 */
final class Attendances
{
    public const CANCELLED_SEANCE = 'Cette séance est annulée : ses présences ne se saisissent plus.';

    public const UNKNOWN_TRAINEE = 'Stagiaire introuvable sur cette session.';

    public const EXEMPTED = 'Ce stagiaire est dispensé de cette séance.';

    public const NOT_ATTENDING = 'Seul un stagiaire inscrit, confirmé ou sorti en cours de formation a des présences.';

    /**
     * @throws ValidationException
     */
    public function record(Seance $seance, string $enrolmentId, string $status, ?int $minutes, ?string $reason): Attendance
    {
        if (! in_array($status, Attendance::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Statut de présence inconnu.']);
        }
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['reason' => 'Le motif ne doit pas dépasser 300 caractères.']);
        }

        return DB::transaction(function () use ($seance, $enrolmentId, $status, $minutes, $reason): Attendance {
            $locked = self::lock($seance);
            $enrolment = self::enrolmentOf($locked, $enrolmentId);
            self::assertExpected($enrolment, $locked->date, $locked);
            $duration = IndividualPlanning::minutesAt($enrolment->id, $locked);
            $realised = match ($status) {
                Attendance::PRESENT => $duration,
                Attendance::PARTIAL => self::partial($minutes, $duration),
                default => 0,
            };
            $attendance = Attendance::query()->where('seance_id', $locked->id)->where('enrolment_id', $enrolment->id)->lockForUpdate()->first()
                ?? new Attendance(['seance_id' => $locked->id, 'enrolment_id' => $enrolment->id]);
            $attendance->fill([
                'status' => $status,
                'minutes' => $realised,
                'reason' => $reason,
                'needs_review' => false,
                'recorded_by' => self::userId(),
                'source' => null,
            ])->save();

            return $attendance;
        });
    }

    /**
     * « Tous présents »: the expected trainees without attendance yet are
     * marked present; returns how many.
     */
    public function allPresent(Seance $seance): int
    {
        return DB::transaction(function () use ($seance): int {
            $locked = self::lock($seance);
            $entered = Attendance::query()->where('seance_id', $locked->id)->pluck('enrolment_id')->all();
            $count = 0;
            $missing = self::expected($locked)->whereNotIn('id', $entered)->orderBy('id')->get();
            $times = IndividualPlanning::times($locked, $missing->pluck('id')->all());
            foreach ($missing as $enrolment) {
                (new Attendance([
                    'seance_id' => $locked->id,
                    'enrolment_id' => $enrolment->id,
                    'status' => Attendance::PRESENT,
                    'minutes' => $times[$enrolment->id]['minutes'],
                    'needs_review' => false,
                    'recorded_by' => self::userId(),
                ]))->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * The attendance computed from the online signatures (SlotValidation),
     * under the same lock and rules as record(): written only when the row
     * is missing or was itself written from the signatures (source =
     * signing) - an attendance entered by hand is never overwritten.
     * Returns whether a row was written.
     */
    public function fromSigning(Seance $seance, string $enrolmentId, string $status, int $minutes): bool
    {
        return DB::transaction(function () use ($seance, $enrolmentId, $status, $minutes): bool {
            $locked = self::lock($seance);
            $enrolment = self::enrolmentOf($locked, $enrolmentId);
            self::assertExpected($enrolment, $locked->date, $locked);
            $attendance = Attendance::query()->where('seance_id', $locked->id)->where('enrolment_id', $enrolment->id)->lockForUpdate()->first();
            if ($attendance !== null && $attendance->source !== Attendance::SOURCE_SIGNING) {
                return false;
            }
            $attendance ??= new Attendance(['seance_id' => $locked->id, 'enrolment_id' => $enrolment->id]);
            $attendance->fill([
                'status' => $status,
                'minutes' => $status === Attendance::PRESENT ? IndividualPlanning::minutesAt($enrolment->id, $locked) : $minutes,
                'reason' => null,
                'needs_review' => false,
                'recorded_by' => self::userId(),
                'source' => Attendance::SOURCE_SIGNING,
            ])->save();

            return true;
        });
    }

    /**
     * Removes the attendance of a trainee at a séance (journaled), if any.
     */
    public function clear(Seance $seance, string $enrolmentId): void
    {
        DB::transaction(function () use ($seance, $enrolmentId): void {
            $locked = self::lock($seance);
            Attendance::query()->where('seance_id', $locked->id)->where('enrolment_id', $enrolmentId)->lockForUpdate()->first()?->delete();
        });
    }

    /**
     * The trainees expected at the séance (see the class), as a query on
     * enrolments.
     *
     * @return Builder<Enrolment>
     */
    public static function expected(Seance $seance): Builder
    {
        return IndividualPlanning::expectedAt($seance);
    }

    /** States of a trainee who has attendance (abandons up to their leaving day). */
    public const ATTENDING = [EnrolmentStatus::Enrolled->value, EnrolmentStatus::Confirmed->value, EnrolmentStatus::Abandoned->value];

    /**
     * The trainee is expected on $day: 422 (French) otherwise.
     *
     * @throws ValidationException
     */
    public static function assertExpected(Enrolment $enrolment, CarbonImmutable $day, ?Seance $seance = null): void
    {
        if (! in_array($enrolment->status->value, self::ATTENDING, true)) {
            throw ValidationException::withMessages(['enrolment_id' => self::NOT_ATTENDING]);
        }
        $date = $day->toDateString();
        if ($enrolment->entered_on !== null && $date < $enrolment->entered_on->toDateString()) {
            throw ValidationException::withMessages(['enrolment_id' => 'Ce stagiaire est entré en formation le '.$enrolment->entered_on->format('d/m/Y').' : pas de présence avant cette date.']);
        }
        if ($enrolment->left_on !== null && $date > $enrolment->left_on->toDateString()) {
            throw ValidationException::withMessages(['enrolment_id' => 'Ce stagiaire a quitté la formation le '.$enrolment->left_on->format('d/m/Y').' : pas de présence après cette date.']);
        }
        if ($seance !== null && IndividualPlanning::exempted($enrolment->id, $seance)) {
            throw ValidationException::withMessages(['enrolment_id' => self::EXEMPTED]);
        }
    }

    /**
     * Seances::onScheduleChange hook, inside the séance's transaction and
     * under its lock: the séance moved to other hours (« à revoir »: present
     * recomputed, a partial at or above the new duration capped to it and
     * made present) or was cancelled (0 minute, kept, « à revoir »). Each
     * changed row is journaled; a failure rolls the séance change back.
     */
    public static function review(Seance $seance, string $kind): void
    {
        if ($kind === SeanceChanged::DELETED) {
            return;
        }
        /** @var Collection<int, Attendance> $rows */
        $rows = Attendance::query()->where('seance_id', $seance->id)->orderBy('id')->lockForUpdate()->get();
        $times = IndividualPlanning::times($seance, $rows->pluck('enrolment_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        foreach ($rows as $attendance) {
            $duration = $times[$attendance->enrolment_id]['minutes'];
            $attendance->needs_review = true;
            if ($kind === SeanceChanged::CANCELLED || $seance->isCancelled()) {
                $attendance->minutes = 0;
            } elseif ($attendance->status === Attendance::PRESENT) {
                $attendance->minutes = $duration;
            } elseif ($attendance->status === Attendance::PARTIAL && $attendance->minutes >= $duration) {
                $attendance->status = Attendance::PRESENT;
                $attendance->minutes = $duration;
            }
            $attendance->save();
        }
    }

    /**
     * The séance locked for the write, its session held shared: 409 when it
     * is cancelled or its month closed.
     */
    private static function lock(Seance $seance): Seance
    {
        TrainingSession::withTrashed()->whereKey($seance->training_session_id)->sharedLock()->firstOrFail();
        $locked = Seance::query()->lockForUpdate()->findOrFail($seance->id);
        if ($locked->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED_SEANCE);
        }
        Closures::assertOpen($locked->training_session_id, $locked->date->toDateString());

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    private static function enrolmentOf(Seance $seance, string $enrolmentId): Enrolment
    {
        $enrolment = Str::isUuid($enrolmentId)
            ? Enrolment::query()->where('training_session_id', $seance->training_session_id)->find($enrolmentId)
            : null;

        return $enrolment ?? throw ValidationException::withMessages(['enrolment_id' => self::UNKNOWN_TRAINEE]);
    }

    /**
     * @throws ValidationException
     */
    private static function partial(?int $minutes, int $duration): int
    {
        if ($minutes === null) {
            throw ValidationException::withMessages(['minutes' => 'Indiquez les minutes de présence.']);
        }
        if ($minutes <= 0 || $minutes >= $duration) {
            throw ValidationException::withMessages(['minutes' => 'Une présence partielle dure entre 1 minute et la durée de la séance ('.SessionModules::label($duration).') exclue.']);
        }

        return $minutes;
    }

    private static function userId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
