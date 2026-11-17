<?php

namespace App\Modules\Sessions\Attendance;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Closure of the attendance of a session for a Paris month (spec § 4 bis 5):
 * once closed, its attendance, its monthly hours and the creation, deletion,
 * cancellation or change of hours of its séances are refused (409, French)
 * until it is reopened — sessions.attendance.close and a mandatory reason,
 * journaled (the reason kept on the row, never in clear in the journal).
 * Invoices (0.9) rely on closed hours.
 *
 * Close and reopen lock the session row; the writers of attendance and
 * monthly hours hold it shared while they check: a month never closes in
 * the middle of a write.
 */
final class Closures
{
    public const CLOSED = 'Le mois de %s est clôturé pour cette session : rouvrez-le pour modifier ses présences, ses heures ou ses séances.';

    public const ALREADY_CLOSED = 'Ce mois est déjà clôturé.';

    public const NOT_CLOSED = 'Ce mois n\'est pas clôturé.';

    public const OUTSIDE = 'Ce mois est hors des dates de la session.';

    /**
     * @throws ValidationException
     */
    public function close(TrainingSession $session, string $month): void
    {
        $first = self::sessionMonth($session, $month);

        DB::transaction(function () use ($session, $first): void {
            TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);
            $closure = AttendanceClosure::query()->where('training_session_id', $session->id)->where('month', $first->toDateString())->lockForUpdate()->first()
                ?? new AttendanceClosure(['training_session_id' => $session->id, 'month' => $first->toDateString()]);
            if ($closure->isClosed()) {
                throw new UserFacingHttpException(409, self::ALREADY_CLOSED);
            }
            $closure->closed_at = CarbonImmutable::now();
            $closure->closed_by = self::userId();
            $closure->save();
        });
    }

    /**
     * @throws ValidationException
     */
    public function reopen(TrainingSession $session, string $month, string $reason): void
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'max:300']], [
            'reason.required' => 'Indiquez le motif de la réouverture.',
            'reason.max' => 'Le motif ne doit pas dépasser 300 caractères.',
        ])->validate();
        $first = self::sessionMonth($session, $month);

        DB::transaction(function () use ($session, $first, $reason): void {
            TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);
            $closure = AttendanceClosure::query()->where('training_session_id', $session->id)->where('month', $first->toDateString())->lockForUpdate()->first();
            if ($closure === null || ! $closure->isClosed()) {
                throw new UserFacingHttpException(409, self::NOT_CLOSED);
            }
            $closure->closed_at = null;
            $closure->closed_by = null;
            $closure->reopened_at = CarbonImmutable::now();
            $closure->reopened_by = self::userId();
            $closure->reopen_reason = $reason;
            $closure->save();
        });
    }

    /** The Paris month of $date is closed for the session. */
    public function isClosed(TrainingSession $session, CarbonImmutable $date): bool
    {
        return self::closed($session->id, Months::of($date->toDateString()));
    }

    /**
     * The closed months of the session, « 2026-09 », in order.
     *
     * @return list<string>
     */
    public function closedMonths(TrainingSession $session): array
    {
        return AttendanceClosure::query()->where('training_session_id', $session->id)->whereNotNull('closed_at')->orderBy('month')
            ->get(['month'])->map(fn (AttendanceClosure $c) => Months::key($c->month))->values()->all();
    }

    /**
     * 409 when the Paris month of $date (« Y-m-d ») is closed for the session.
     */
    public static function assertOpen(string $sessionId, string $date): void
    {
        $first = Months::of($date);
        if (self::closed($sessionId, $first)) {
            throw new UserFacingHttpException(409, sprintf(self::CLOSED, Months::label($first)));
        }
    }

    /**
     * 409 when a month between the Paris months of $from and $to (« Y-m-d »;
     * null = the end of the session) is closed: the dates of a trainee
     * decide which hours count, so moving them there changes closed hours.
     */
    public static function assertOpenBetween(string $sessionId, string $from, ?string $to = null): void
    {
        $first = Months::of($from);
        $closed = AttendanceClosure::query()->where('training_session_id', $sessionId)->whereNotNull('closed_at')
            ->where('month', '>=', $first->toDateString())
            ->when($to !== null, fn ($q) => $q->where('month', '<=', Months::of((string) $to)->toDateString()))
            ->orderBy('month')->first(['month']);
        if ($closed !== null) {
            throw new UserFacingHttpException(409, sprintf(self::CLOSED, Months::label($closed->month)));
        }
    }

    private static function closed(string $sessionId, CarbonImmutable $first): bool
    {
        return AttendanceClosure::query()->where('training_session_id', $sessionId)->where('month', $first->toDateString())
            ->whereNotNull('closed_at')->exists();
    }

    /**
     * @throws ValidationException
     */
    private static function sessionMonth(TrainingSession $session, string $month): CarbonImmutable
    {
        $first = Months::required($month);
        if (! in_array(Months::key($first), Months::ofSession($session), true)) {
            throw ValidationException::withMessages(['month' => self::OUTSIDE]);
        }

        return $first;
    }

    private static function userId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
