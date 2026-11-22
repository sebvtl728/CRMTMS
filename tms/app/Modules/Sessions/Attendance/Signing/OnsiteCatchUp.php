<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The on-site catch-up of a past half-day (2026-11-21): an attendance
 * manager opens a link of its own (never the normal one, only its SHA-256
 * kept) on a past half-day (prepared when never opened), in an open month; the device
 * passed round lists the expected trainees who have not signed. The link
 * dies when closed, at the end of the opening day (Paris), or when the
 * month is closed. Each signature waits for a confirmation.
 */
final class OnsiteCatchUp
{
    public const TODAY = 'Le jour même, utilisez l\'émargement en ligne.';

    /**
     * The half-day of a past day for a catch-up, created closed (no signing
     * link) when it was never opened; the existing one otherwise.
     *
     * @throws UserFacingHttpException
     */
    public function prepare(TrainingSession $session, string $date, string $half, User $by): SignSlot
    {
        if ($date >= TrainingSession::today()->toDateString()) {
            throw new UserFacingHttpException(409, self::TODAY);
        }

        return DB::transaction(function () use ($session, $date, $half, $by): SignSlot {
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, AttendanceSigning::CANCELLED_SESSION);
            }
            $slot = SignSlot::query()->where('training_session_id', $locked->id)->where('date', $date)->where('half', $half)->first();
            if ($slot !== null) {
                return $slot;
            }
            if (SlotSeances::of($locked->id, $date, $half)->isEmpty()) {
                throw new UserFacingHttpException(409, $half === SignSlot::MORNING ? 'Aucune séance ce matin.' : 'Aucune séance cet après-midi.');
            }
            Closures::assertOpen($locked->id, $date);

            return SignSlot::query()->create([
                'training_session_id' => $locked->id, 'date' => $date, 'half' => $half,
                'status' => SignSlot::CLOSED, 'opened_at' => CarbonImmutable::now(), 'opened_by' => $by->id,
            ]);
        });
    }

    /**
     * Opens (or renews) the catch-up: a new token, the previous one dead.
     *
     * @return string the token in clear, for the URL only
     *
     * @throws UserFacingHttpException
     */
    public function open(SignSlot $slot, User $by): string
    {
        if ($slot->date->toDateString() >= TrainingSession::today()->toDateString()) {
            throw new UserFacingHttpException(409, self::TODAY);
        }
        $token = Str::random(40);
        DB::transaction(function () use ($slot, $by, $token): void {
            $session = TrainingSession::query()->lockForUpdate()->findOrFail($slot->training_session_id);
            if ($session->isCancelled()) {
                throw new UserFacingHttpException(409, AttendanceSigning::CANCELLED_SESSION);
            }
            $locked = SignSlot::query()->lockForUpdate()->findOrFail($slot->id);
            Closures::assertOpen($locked->training_session_id, $locked->date->toDateString());
            $locked->fill([
                'catch_up_token_hash' => hash('sha256', $token),
                'catch_up_opened_at' => CarbonImmutable::now(),
                'catch_up_opened_by' => $by->id,
                'catch_up_expires_at' => TrainingSession::today()->endOfDay(),
            ])->save();
        });

        return $token;
    }

    public function close(SignSlot $slot): void
    {
        SignSlot::query()->whereKey($slot->id)->update(['catch_up_token_hash' => null, 'catch_up_expires_at' => null]);
    }

    /**
     * The half-day of a live on-site catch-up link; null for an unknown,
     * malformed, expired or closed link, a closed month, a cancelled or
     * trashed session: the caller answers one 404.
     */
    public static function find(string $token): ?SignSlot
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $slot = SignSlot::query()->where('catch_up_token_hash', hash('sha256', $token))->first();
        if ($slot === null || $slot->catch_up_expires_at === null || ! $slot->catch_up_expires_at->isFuture()) {
            return null;
        }
        $session = TrainingSession::query()->find($slot->training_session_id);
        if ($session === null || $session->isCancelled()) {
            return null;
        }
        $closed = AttendanceClosure::query()->where('training_session_id', $slot->training_session_id)
            ->where('month', Months::of($slot->date->toDateString())->toDateString())->whereNotNull('closed_at')->exists();

        return $closed ? null : $slot;
    }

    /** « Rattrapage du jeudi 10 septembre 2026, matin ». */
    public static function banner(SignSlot $slot): string
    {
        return 'Rattrapage du '.$slot->date->locale('fr')->isoFormat('dddd D MMMM YYYY').', '.mb_strtolower(SignSlot::label($slot->half));
    }
}
