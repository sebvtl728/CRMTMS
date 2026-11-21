<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Signatures\SignatureDrawing;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The validation of a half-day (spec § 6.3), in one transaction: the
 * validator's drawing archived, the slot validated, then for every séance of
 * the half-day and every trainee expected, the attendance computed on the
 * validated half-days of that séance - present when signed on all the
 * half-days it covers, partial (prorated, Halves::minutes) when signed on a
 * part only, absent otherwise - written by Attendances::fromSigning (an
 * attendance entered by hand is kept). A closed month rolls everything back.
 */
final class SlotValidation
{
    public const OTHER_TRAINER = 'Une autre séance de cette demi-journée est animée par un autre formateur : un gestionnaire des présences la valide.';

    public function __construct(private readonly Attendances $attendances) {}

    /**
     * @param  string  $drawing  PNG data URI of the validator's signature
     *
     * @throws UserFacingHttpException
     */
    public function validate(SignSlot $slot, string $drawing, User $by): SignSlot
    {
        $png = SignatureDrawing::decode($drawing);
        $seances = SlotSeances::ofSlot($slot);
        $ids = $seances->pluck('id')->all();
        $reached = SessionsVisibility::seances(Seance::query()->whereKey($ids), $by, Permissions::ATTENDANCE_MANAGE)->count();
        if ($reached !== count($ids)) {
            throw new UserFacingHttpException(403, self::OTHER_TRAINER);
        }
        $trainer = Trainers::forUser($by);
        $role = $trainer !== null && $seances->contains(fn (Seance $s) => $s->trainer_id === $trainer->id) ? SignSlot::ROLE_TRAINER : SignSlot::ROLE_MANAGER;

        return $this->complete($slot, $png, $by->name, $role, $by->id);
    }

    /**
     * Validates with an already decoded drawing (or none, from a connector).
     */
    public function complete(SignSlot $slot, ?string $png, string $name, string $role, ?string $userId): SignSlot
    {
        $path = $png === null ? null : AttendanceSigning::drawingPath($slot, 'validation-'.CarbonImmutable::now()->format('YmdHisu'));
        try {
            return DB::transaction(function () use ($slot, $png, $name, $role, $userId, $path): SignSlot {
                // Session first, as open() does: every validation of the session's day is serialised
                // (morning and afternoon at once never lose each other's signatures) and never deadlocks.
                TrainingSession::withTrashed()->lockForUpdate()->findOrFail($slot->training_session_id);
                $locked = SignSlot::query()->lockForUpdate()->findOrFail($slot->id);
                if ($locked->isValidated()) {
                    throw new UserFacingHttpException(409, AttendanceSigning::ALREADY_VALIDATED);
                }
                if ($png !== null) {
                    Storage::disk(TenantStorage::DISK)->put((string) $path, $png);
                }
                $locked->fill([
                    'status' => SignSlot::VALIDATED,
                    'token_hash' => null,
                    'token_encrypted' => null,
                    'validated_at' => CarbonImmutable::now(),
                    'validated_by' => $userId,
                    'trainer_name' => mb_substr($name, 0, 200),
                    'validator_role' => $role,
                    'trainer_signature_path' => $path,
                    'trainer_signature_sha256' => $png === null ? null : hash('sha256', $png),
                ])->save();
                $this->writeAttendance($locked);

                return $locked;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk(TenantStorage::DISK)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Writes the attendance of a validated half-day again (a catch-up was
     * confirmed): inside the caller's transaction, slot and session locked.
     */
    public function recompute(SignSlot $slot): void
    {
        if ($slot->isValidated()) {
            $this->writeAttendance($slot);
        }
    }

    private function writeAttendance(SignSlot $slot): void
    {
        $date = $slot->date->toDateString();
        $validated = SignSlot::query()->where('training_session_id', $slot->training_session_id)->where('date', $date)
            ->where('status', SignSlot::VALIDATED)->get()->keyBy('half');
        $signed = AttendanceSignature::query()->counted()->whereIn('slot_id', $validated->pluck('id')->all())->get(['slot_id', 'enrolment_id'])
            ->groupBy('enrolment_id')->map(fn ($rows) => $rows->map(fn (AttendanceSignature $r) => $validated->firstWhere('id', $r->slot_id)?->half)->filter()->values()->all());
        $expected = SlotSeances::expected($slot);
        foreach (SlotSeances::ofSlot($slot) as $seance) {
            $halves = Halves::of($seance->start(), $seance->end());
            foreach ($expected as $enrolment) {
                $mine = array_values(array_intersect($halves, $signed[$enrolment->id] ?? []));
                $minutes = Halves::minutes($seance->duration_minutes, $seance->start(), $seance->end(), $mine);
                [$status, $minutes] = match (true) {
                    count($mine) === count($halves) => [Attendance::PRESENT, $seance->duration_minutes],
                    $minutes > 0 => [Attendance::PARTIAL, max(1, min($seance->duration_minutes - 1, $minutes))],
                    default => [Attendance::ABSENT, 0],
                };
                $this->attendances->fromSigning($seance, $enrolment->id, $status, $minutes);
            }
        }
    }
}
