<?php

namespace App\Modules\Sessions\Planning;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\Halves;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The writes of the individual planning of a trainee (spec 2026-11-24 § 7).
 * Each one is a transaction: the session(s) of the séances held shared (as
 * Seances does), the séances locked in id order, then the enrolment, and
 * his planned hours computed again (IndividualPlanning::refresh) at the
 * end. Never on a cancelled séance nor in a closed month; never over an
 * attendance or a signature already entered (it is cleared first).
 */
final class IndividualPlanningWriter
{
    public const HAS_ATTENDANCE = 'Une présence est saisie sur cette séance pour ce stagiaire : effacez-la d\'abord.';

    public const HAS_MONTHLY_HOURS = 'Des heures sont saisies sur ce module pour ce stagiaire : effacez-les d\'abord.';

    public const NOT_HIS = 'Cette séance n\'est pas dans le planning de ce stagiaire.';

    public const ALREADY_MODULE = 'Ce stagiaire est déjà dispensé de tout le module.';

    public const OUT_OF_SEANCE = 'Ses horaires doivent rester dans ceux de la séance.';

    public const NOT_LIVE = 'Seul un stagiaire inscrit ou confirmé a un planning individuel.';

    public const UNKNOWN_MODULE = 'Module introuvable sur cette session.';

    public const ADDED_NOT_EXEMPT = 'Cette séance lui a été ajoutée : retirez-la de son planning plutôt que de l\'en dispenser.';

    public const OWN_SESSION = 'Cette séance est déjà dans sa session : rien à ajouter.';

    public const ALREADY_PLANNED = 'Cette séance est déjà dans son planning.';

    public const OUT_OF_DATES = 'Cette séance tombe hors de ses dates d\'entrée et de sortie.';

    public const HOST_UNAVAILABLE = 'Cette session est annulée ou à la corbeille.';

    /**
     * Séances of other sessions added to his planning (a catch-up, a
     * personalised path): his hours, his dossier; never counted in the host
     * session (its trainees, places, hours, invoicing).
     *
     * @param  list<string>  $seanceIds
     *
     * @throws ValidationException
     */
    public function addSeances(Enrolment $enrolment, array $seanceIds, ?string $reason): void
    {
        $reason = self::reason($reason);
        $this->write($enrolment, $seanceIds, function (Enrolment $locked, Collection $seances) use ($reason): void {
            $hosts = TrainingSession::query()->whereKey($seances->pluck('training_session_id')->unique()->values()->all())->get()->keyBy('id');
            foreach ($seances as $seance) {
                if ($seance->training_session_id === $locked->training_session_id) {
                    throw ValidationException::withMessages(['seance_ids' => self::OWN_SESSION]);
                }
                $host = $hosts->get($seance->training_session_id);
                if ($host === null || $host->isCancelled()) {
                    throw ValidationException::withMessages(['seance_ids' => self::HOST_UNAVAILABLE]);
                }
                if (self::adjustmentAt($locked->id, $seance->id) !== null) {
                    throw ValidationException::withMessages(['seance_ids' => self::ALREADY_PLANNED]);
                }
                $date = $seance->date->toDateString();
                if (($locked->entered_on !== null && $date < $locked->entered_on->toDateString())
                    || ($locked->left_on !== null && $date > $locked->left_on->toDateString())) {
                    throw ValidationException::withMessages(['seance_ids' => self::OUT_OF_DATES]);
                }
                EnrolmentAdjustment::query()->create([
                    'enrolment_id' => $locked->id, 'kind' => EnrolmentAdjustment::ADDED_SEANCE, 'seance_id' => $seance->id, 'reason' => $reason,
                ]);
            }
        });
    }

    /**
     * @param  list<string>  $seanceIds
     *
     * @throws ValidationException
     */
    public function exemptSeances(Enrolment $enrolment, array $seanceIds, ?string $reason): void
    {
        $reason = self::reason($reason);
        $this->write($enrolment, $seanceIds, function (Enrolment $locked, Collection $seances) use ($reason): void {
            foreach ($seances as $seance) {
                self::assertHis($locked, $seance);
                if (self::moduleExempted($locked->id, $seance->session_module_id)) {
                    throw ValidationException::withMessages(['seance_ids' => self::ALREADY_MODULE]);
                }
                $current = self::adjustmentAt($locked->id, $seance->id);
                if ($current?->kind === EnrolmentAdjustment::ADDED_SEANCE) {
                    throw ValidationException::withMessages(['seance_ids' => self::ADDED_NOT_EXEMPT]);
                }
                self::assertNothingEntered($locked->id, $seance);
                if ($current?->kind === EnrolmentAdjustment::EXEMPT_SEANCE) {
                    continue;
                }
                $current?->delete();
                EnrolmentAdjustment::query()->create([
                    'enrolment_id' => $locked->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $seance->id, 'reason' => $reason,
                ]);
            }
        });
    }

    /**
     * @throws ValidationException
     */
    public function exemptModule(Enrolment $enrolment, string $moduleId, ?string $reason): void
    {
        $reason = self::reason($reason);
        $module = Str::isUuid($moduleId) ? SessionModule::query()->where('training_session_id', $enrolment->training_session_id)->find($moduleId) : null;
        if ($module === null) {
            throw ValidationException::withMessages(['module_id' => self::UNKNOWN_MODULE]);
        }
        $seanceIds = Seance::query()->where('session_module_id', $module->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->write($enrolment, $seanceIds, function (Enrolment $locked, Collection $seances) use ($module, $reason): void {
            if (self::moduleExempted($locked->id, $module->id)) {
                return;
            }
            foreach ($seances as $seance) {
                self::assertNoAttendance($locked->id, $seance);
            }
            if (MonthlyHour::query()->where('enrolment_id', $locked->id)->where('session_module_id', $module->id)->where('minutes', '>', 0)->exists()) {
                throw new UserFacingHttpException(409, self::HAS_MONTHLY_HOURS);
            }
            EnrolmentAdjustment::query()->where('enrolment_id', $locked->id)->whereIn('seance_id', $seances->pluck('id')->all())->get()->each->delete();
            EnrolmentAdjustment::query()->create([
                'enrolment_id' => $locked->id, 'kind' => EnrolmentAdjustment::EXEMPT_MODULE, 'session_module_id' => $module->id, 'reason' => $reason,
            ]);
        }, checkSeances: false);
    }

    /**
     * His own hours at a séance of his planning (« 14:00 », « 17:00 »),
     * within the séance's; the séance's own hours clear them.
     *
     * @throws ValidationException
     */
    public function setOwnHours(Enrolment $enrolment, string $seanceId, string $start, string $end, int $break): void
    {
        foreach (['starts_at' => $start, 'ends_at' => $end] as $key => $time) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw ValidationException::withMessages([$key => 'Indiquez une heure au format 14:00.']);
            }
        }
        $this->write($enrolment, [$seanceId], function (Enrolment $locked, Collection $seances) use ($start, $end, $break): void {
            $seance = $seances->sole();
            self::assertHis($locked, $seance);
            if (IndividualPlanning::exempted($locked->id, $seance)) {
                throw ValidationException::withMessages(['seance_id' => self::NOT_HIS]);
            }
            $span = self::minutesOfDay($end) - self::minutesOfDay($start);
            if ($start < $seance->start() || $end > $seance->end() || $span <= 0 || $break < 0 || $span - $break <= 0) {
                throw ValidationException::withMessages(['starts_at' => self::OUT_OF_SEANCE]);
            }
            $current = self::adjustmentAt($locked->id, $seance->id);
            $same = $start === $seance->start() && $end === $seance->end() && $break === $seance->break_minutes;
            if ($same) {
                self::dropHours($current);
            } elseif ($current !== null) {
                $current->fill(['starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $break])->save();
            } else {
                EnrolmentAdjustment::query()->create([
                    'enrolment_id' => $locked->id, 'kind' => EnrolmentAdjustment::OWN_HOURS, 'seance_id' => $seance->id,
                    'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $break,
                ]);
            }
            Attendances::reviewTrainee($seance, $locked->id);
        });
    }

    /**
     * @throws ValidationException
     */
    public function clearOwnHours(Enrolment $enrolment, string $seanceId): void
    {
        $this->write($enrolment, [$seanceId], function (Enrolment $locked, Collection $seances): void {
            $seance = $seances->sole();
            self::dropHours(self::adjustmentAt($locked->id, $seance->id));
            Attendances::reviewTrainee($seance, $locked->id);
        });
    }

    /**
     * Removes one adjustment (an exemption, own hours, a séance added).
     *
     * @throws ValidationException
     */
    public function remove(EnrolmentAdjustment $adjustment): void
    {
        $enrolment = Enrolment::query()->findOrFail($adjustment->enrolment_id);
        $seanceIds = $adjustment->seance_id === null ? [] : [$adjustment->seance_id];
        $this->write($enrolment, $seanceIds, function (Enrolment $locked, Collection $seances) use ($adjustment): void {
            $row = EnrolmentAdjustment::query()->lockForUpdate()->find($adjustment->id);
            if ($row === null) {
                return;
            }
            $seance = $seances->first();
            if ($row->kind === EnrolmentAdjustment::EXEMPT_MODULE && $row->session_module_id !== null) {
                // He becomes expected again on the whole module: never in a closed month.
                foreach (Seance::query()->where('session_module_id', $row->session_module_id)->where('status', Seance::PLANNED)->get(['training_session_id', 'date']) as $s) {
                    Closures::assertOpen($s->training_session_id, $s->date->toDateString());
                }
            }
            if ($row->kind === EnrolmentAdjustment::ADDED_SEANCE && $seance !== null) {
                self::assertNothingEntered($locked->id, $seance);
            }
            $row->delete();
            if ($row->kind === EnrolmentAdjustment::OWN_HOURS && $seance !== null) {
                Attendances::reviewTrainee($seance, $locked->id);
            }
        }, checkSeances: $adjustment->kind !== EnrolmentAdjustment::EXEMPT_MODULE);
    }

    /**
     * The common frame: sessions held shared and séances locked (id order),
     * each live and in an open month when $checkSeances, then the enrolment
     * locked and live; $change runs; his planned hours computed again.
     *
     * @param  list<string>  $seanceIds
     * @param  callable(Enrolment, Collection<int, Seance>): void  $change
     */
    private function write(Enrolment $enrolment, array $seanceIds, callable $change, bool $checkSeances = true): void
    {
        $ids = array_values(array_unique(array_filter($seanceIds, fn (string $id) => Str::isUuid($id))));
        if (count($ids) !== count(array_unique($seanceIds))) {
            throw ValidationException::withMessages(['seance_ids' => self::NOT_HIS]);
        }
        DB::transaction(function () use ($enrolment, $ids, $change, $checkSeances): void {
            $sessionIds = Seance::query()->whereKey($ids)->pluck('training_session_id')->push($enrolment->training_session_id)->unique()->sort()->values()->all();
            TrainingSession::withTrashed()->whereKey($sessionIds)->orderBy('id')->sharedLock()->get();
            $seances = Seance::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($seances->count() !== count($ids)) {
                throw ValidationException::withMessages(['seance_ids' => self::NOT_HIS]);
            }
            if ($checkSeances) {
                foreach ($seances as $seance) {
                    if ($seance->isCancelled()) {
                        throw new UserFacingHttpException(409, Attendances::CANCELLED_SEANCE);
                    }
                    Closures::assertOpen($seance->training_session_id, $seance->date->toDateString());
                }
            }
            $locked = Enrolment::query()->lockForUpdate()->findOrFail($enrolment->id);
            if (! in_array($locked->status->value, Attendances::ATTENDING, true)) {
                throw ValidationException::withMessages(['enrolment_id' => self::NOT_LIVE]);
            }
            $change($locked, $seances);
            IndividualPlanning::refresh($locked);
            $enrolment->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    /**
     * A séance of his session within his entry and leaving days.
     *
     * @throws ValidationException
     */
    private static function assertHis(Enrolment $enrolment, Seance $seance): void
    {
        $date = $seance->date->toDateString();
        $his = $seance->training_session_id === $enrolment->training_session_id
            || self::adjustmentAt($enrolment->id, $seance->id)?->kind === EnrolmentAdjustment::ADDED_SEANCE;
        if (! $his
            || ($enrolment->entered_on !== null && $date < $enrolment->entered_on->toDateString())
            || ($enrolment->left_on !== null && $date > $enrolment->left_on->toDateString())) {
            throw ValidationException::withMessages(['seance_ids' => self::NOT_HIS]);
        }
    }

    private static function assertNothingEntered(string $enrolmentId, Seance $seance): void
    {
        self::assertNoAttendance($enrolmentId, $seance);
        $halves = Halves::of($seance->start(), $seance->end());
        $signed = AttendanceSignature::query()->where('enrolment_id', $enrolmentId)
            ->whereIn('slot_id', SignSlot::query()->where('training_session_id', $seance->training_session_id)
                ->where('date', $seance->date->toDateString())->whereIn('half', $halves)->select('id'))
            ->exists();
        if ($signed) {
            throw new UserFacingHttpException(409, self::HAS_ATTENDANCE);
        }
    }

    private static function assertNoAttendance(string $enrolmentId, Seance $seance): void
    {
        if (Attendance::query()->where('seance_id', $seance->id)->where('enrolment_id', $enrolmentId)->exists()) {
            throw new UserFacingHttpException(409, self::HAS_ATTENDANCE);
        }
    }

    private static function moduleExempted(string $enrolmentId, string $moduleId): bool
    {
        return EnrolmentAdjustment::query()->where('enrolment_id', $enrolmentId)
            ->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->where('session_module_id', $moduleId)->exists();
    }

    private static function adjustmentAt(string $enrolmentId, string $seanceId): ?EnrolmentAdjustment
    {
        return EnrolmentAdjustment::query()->where('enrolment_id', $enrolmentId)->where('seance_id', $seanceId)->first();
    }

    /** Own hours gone: the row of own hours deleted, an added séance kept at the séance's hours. */
    private static function dropHours(?EnrolmentAdjustment $current): void
    {
        if ($current?->kind === EnrolmentAdjustment::OWN_HOURS) {
            $current->delete();
        } elseif ($current?->kind === EnrolmentAdjustment::ADDED_SEANCE && $current->hasOwnHours()) {
            $current->fill(['starts_at' => null, 'ends_at' => null, 'break_minutes' => null])->save();
        }
    }

    /**
     * @throws ValidationException
     */
    private static function reason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['reason' => 'Le motif ne doit pas dépasser 300 caractères.']);
        }

        return $reason === '' ? null : $reason;
    }

    private static function minutesOfDay(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }
}
