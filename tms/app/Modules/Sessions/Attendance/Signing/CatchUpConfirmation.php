<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An attendance manager confirms or refuses a catch-up signature
 * (2026-11-21): a confirmed catch-up counts as signed and the attendance of
 * a validated half-day is written again (a row entered by hand is never
 * touched); a refused one stays as a trace, without attendance. Session,
 * then half-day locked, as the validation; a closed month refuses both.
 */
final class CatchUpConfirmation
{
    public const NOT_PENDING = 'Ce rattrapage est déjà confirmé ou refusé.';

    public const REASON = 'Indiquez un motif (300 caractères au plus).';

    public function __construct(private readonly SlotValidation $validation) {}

    public function confirm(AttendanceSignature $signature, User $by): void
    {
        $this->review($signature, function (AttendanceSignature $locked, SignSlot $slot) use ($by): void {
            $locked->forceFill(['confirmed_at' => CarbonImmutable::now(), 'confirmed_by' => $by->id])->save();
            $this->validation->recompute($slot);
        });
    }

    /**
     * @throws ValidationException
     */
    public function refuse(AttendanceSignature $signature, User $by, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['reason' => self::REASON]);
        }
        $this->review($signature, function (AttendanceSignature $locked) use ($by, $reason): void {
            $locked->forceFill(['refused_at' => CarbonImmutable::now(), 'refused_by' => $by->id, 'refusal_reason' => $reason])->save();
        });
    }

    /**
     * @param  callable(AttendanceSignature, SignSlot): void  $apply
     */
    private function review(AttendanceSignature $signature, callable $apply): void
    {
        DB::transaction(function () use ($signature, $apply): void {
            $slotId = $signature->slot_id;
            $sessionId = (string) SignSlot::query()->whereKey($slotId)->value('training_session_id');
            TrainingSession::withTrashed()->lockForUpdate()->findOrFail($sessionId);
            $slot = SignSlot::query()->lockForUpdate()->findOrFail($slotId);
            $locked = AttendanceSignature::query()->lockForUpdate()->findOrFail($signature->id);
            if (! $locked->isPendingCatchUp()) {
                throw new UserFacingHttpException(409, self::NOT_PENDING);
            }
            Closures::assertOpen($slot->training_session_id, $slot->date->toDateString());
            $apply($locked, $slot);
        });
    }
}
