<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning as Signing;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotSeances;
use App\Modules\Sessions\Attendance\Signing\SlotValidation;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Public service of the online attendance signing, for a future connector
 * (an external signing tool, adapter in app/Modules/Connectors): it reads
 * the half-days and pushes signatures and validations with the same rules
 * as the shared link (expected trainees, one signature each, closed month
 * refused, attendance written from the signatures, an attendance entered by
 * hand kept). $source is the connector's key, never « online ». Plain
 * arrays and strings only: no internal type crosses this door.
 */
final class AttendanceSigning
{
    public function __construct(private readonly Signing $signing, private readonly SlotValidation $validation) {}

    /**
     * @return list<array{id: string, date: string, half: string, status: string, seance_ids: list<string>, expected: list<array{enrolment_id: string, person_id: string}>}>
     */
    public function slots(string $sessionId, string $from, string $to): array
    {
        return SignSlot::query()->where('training_session_id', $sessionId)->whereBetween('date', [$from, $to])
            ->orderBy('date')->orderByRaw("half = 'afternoon'")->get()
            ->map(fn (SignSlot $s) => [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'half' => $s->half,
                'status' => $s->status,
                'seance_ids' => SlotSeances::ofSlot($s)->pluck('id')->values()->all(),
                'expected' => SlotSeances::expected($s)->map(fn (Enrolment $e) => ['enrolment_id' => $e->id, 'person_id' => (string) $e->person_id])->values()->all(),
            ])->values()->all();
    }

    /**
     * The half-day of a day (created closed, without any link, when
     * missing): an import may come after the day.
     */
    public function ensureSlot(string $sessionId, string $date, string $half, string $source): string
    {
        self::assertSource($source);
        if (! in_array($half, SignSlot::HALVES, true)) {
            throw new InvalidArgumentException('Unknown half-day.');
        }

        return DB::transaction(function () use ($sessionId, $date, $half): string {
            TrainingSession::query()->lockForUpdate()->findOrFail($sessionId);
            $slot = SignSlot::query()->where('training_session_id', $sessionId)->where('date', $date)->where('half', $half)->first();
            if ($slot !== null) {
                return $slot->id;
            }
            Closures::assertOpen($sessionId, $date);

            return SignSlot::query()->create([
                'training_session_id' => $sessionId, 'date' => $date, 'half' => $half,
                'status' => SignSlot::CLOSED, 'opened_at' => CarbonImmutable::now(),
            ])->id;
        });
    }

    /**
     * @param  string|null  $pngDataUri  the drawing when the tool gives one
     */
    public function recordExternal(string $slotId, string $enrolmentId, CarbonImmutable $signedAt, ?string $pngDataUri, string $source): void
    {
        self::assertSource($source);
        $this->signing->sign(SignSlot::query()->findOrFail($slotId), $enrolmentId, $pngDataUri ?? '', '', '', $source, $signedAt, requireOpen: false);
    }

    public function validateExternal(string $slotId, string $source, ?string $trainerPngDataUri, string $trainerName): void
    {
        self::assertSource($source);
        $png = $trainerPngDataUri === null ? null : \App\Modules\Core\Signatures\SignatureDrawing::decode($trainerPngDataUri);
        $this->validation->complete(SignSlot::query()->findOrFail($slotId), $png, $trainerName, SignSlot::ROLE_TRAINER, null);
    }

    private static function assertSource(string $source): void
    {
        if ($source === '' || $source === 'online' || mb_strlen($source) > 40) {
            throw new InvalidArgumentException('A connector signs under its own key.');
        }
    }
}
