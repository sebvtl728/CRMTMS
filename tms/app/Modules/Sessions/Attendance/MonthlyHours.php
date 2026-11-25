<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hours realised per Paris month on a module dated as an interval (spec
 * § 4.7): whole minutes per trainee, module and month (one row), the
 * trainer optional. Only within the months of the module, from the month a
 * late trainee entered up to the month of his abandon; never in a closed
 * month (409). The session row is held shared during the write (Closures
 * locks it to close a month, SessionModules to switch a module's mode).
 */
final class MonthlyHours
{
    public const EXEMPTED = 'Ce stagiaire est dispensé de ce module.';

    /** 31 days of 24 h. */
    public const MAX_MINUTES = 44640;

    public const SEANCES_MODULE = 'Ce module est daté par séances : ses heures se saisissent par les présences.';

    /**
     * @throws ValidationException
     */
    public function record(string $enrolmentId, SessionModule $module, string $month, int $minutes, ?string $trainerId): void
    {
        $first = self::validated($module, $month);
        if ($minutes < 0 || $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['minutes' => 'Indiquez un nombre de minutes entre 0 et 44 640.']);
        }
        $trainerId = $trainerId === null || trim($trainerId) === '' ? null : trim($trainerId);
        if ($trainerId !== null && (! Str::isUuid($trainerId) || ! Trainer::query()->whereKey($trainerId)->exists())) {
            throw ValidationException::withMessages(['trainer_id' => 'Formateur introuvable.']);
        }

        DB::transaction(function () use ($enrolmentId, $module, $first, $minutes, $trainerId): void {
            $enrolment = self::lock($module, $first, $enrolmentId);
            if (in_array($module->id, IndividualPlanning::exemptModules([$enrolment->id])[$enrolment->id] ?? [], true)) {
                throw ValidationException::withMessages(['enrolment_id' => self::EXEMPTED]);
            }
            $row = MonthlyHour::query()->where('enrolment_id', $enrolment->id)->where('session_module_id', $module->id)
                ->where('month', $first->toDateString())->lockForUpdate()->first()
                ?? new MonthlyHour(['enrolment_id' => $enrolment->id, 'session_module_id' => $module->id, 'month' => $first->toDateString()]);
            $id = Auth::id();
            $row->fill(['minutes' => $minutes, 'trainer_id' => $trainerId, 'recorded_by' => $id === null ? null : (string) $id])->save();
        });
    }

    /**
     * Removes the hours of a trainee on a module for a month (journaled), if any.
     *
     * @throws ValidationException
     */
    public function clear(string $enrolmentId, SessionModule $module, string $month): void
    {
        $first = Months::required($month);
        DB::transaction(function () use ($enrolmentId, $module, $first): void {
            TrainingSession::withTrashed()->whereKey($module->training_session_id)->sharedLock()->firstOrFail();
            Closures::assertOpen($module->training_session_id, $first->toDateString());
            // Like record(): an interval module, a trainee of its session (his dates aside: what was entered may go).
            if (SessionModule::query()->findOrFail($module->id)->dating_mode !== DatingMode::Interval->value) {
                throw ValidationException::withMessages(['session_module_id' => self::SEANCES_MODULE]);
            }
            if (! Str::isUuid($enrolmentId) || ! Enrolment::query()->where('training_session_id', $module->training_session_id)->whereKey($enrolmentId)->exists()) {
                throw ValidationException::withMessages(['enrolment_id' => Attendances::UNKNOWN_TRAINEE]);
            }
            MonthlyHour::query()->where('enrolment_id', $enrolmentId)->where('session_module_id', $module->id)
                ->where('month', $first->toDateString())->lockForUpdate()->first()?->delete();
        });
    }

    /**
     * The months of a module (its dates, else its session's), « 2026-09 ».
     *
     * @return list<string>
     */
    public static function monthsOf(SessionModule $module, TrainingSession $session): array
    {
        return Months::between($module->starts_on ?? $session->starts_on, $module->ends_on ?? $session->ends_on);
    }

    /**
     * The trainee may have hours on $first (his entry and leaving months).
     *
     * @throws ValidationException
     */
    public static function assertExpected(Enrolment $enrolment, CarbonImmutable $first): void
    {
        if (! in_array($enrolment->status->value, Attendances::ATTENDING, true)) {
            throw ValidationException::withMessages(['enrolment_id' => Attendances::NOT_ATTENDING]);
        }
        if ($enrolment->entered_on !== null && $first->lt(Months::of($enrolment->entered_on->toDateString()))) {
            throw ValidationException::withMessages(['enrolment_id' => 'Ce stagiaire est entré en formation le '.$enrolment->entered_on->format('d/m/Y')
                .' : pas d\'heures avant '.Months::label(Months::of($enrolment->entered_on->toDateString())).'.']);
        }
        if ($enrolment->left_on !== null && $first->gt(Months::of($enrolment->left_on->toDateString()))) {
            throw ValidationException::withMessages(['enrolment_id' => 'Ce stagiaire a quitté la formation le '.$enrolment->left_on->format('d/m/Y')
                .' : pas d\'heures après '.Months::label(Months::of($enrolment->left_on->toDateString())).'.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function validated(SessionModule $module, string $month): CarbonImmutable
    {
        if ($module->dating_mode !== DatingMode::Interval->value) {
            throw ValidationException::withMessages(['session_module_id' => self::SEANCES_MODULE]);
        }
        $first = Months::required($month);
        $session = TrainingSession::withTrashed()->findOrFail($module->training_session_id);
        $months = self::monthsOf($module, $session);
        if (! in_array(Months::key($first), $months, true)) {
            throw ValidationException::withMessages(['month' => 'Ce mois est hors des dates du module ('.Months::label($months[0]).' à '.Months::label($months[array_key_last($months)]).').']);
        }

        return $first;
    }

    /**
     * Session held shared, month open, module still an interval, the
     * trainee of the session expected that month.
     *
     * @throws ValidationException
     */
    private static function lock(SessionModule $module, CarbonImmutable $first, string $enrolmentId): Enrolment
    {
        TrainingSession::withTrashed()->whereKey($module->training_session_id)->sharedLock()->firstOrFail();
        Closures::assertOpen($module->training_session_id, $first->toDateString());
        $current = SessionModule::query()->findOrFail($module->id);
        if ($current->dating_mode !== DatingMode::Interval->value) {
            throw ValidationException::withMessages(['session_module_id' => self::SEANCES_MODULE]);
        }
        $enrolment = Str::isUuid($enrolmentId)
            ? Enrolment::query()->where('training_session_id', $module->training_session_id)->find($enrolmentId)
            : null;
        if ($enrolment === null) {
            throw ValidationException::withMessages(['enrolment_id' => Attendances::UNKNOWN_TRAINEE]);
        }
        self::assertExpected($enrolment, $first);

        return $enrolment;
    }
}
