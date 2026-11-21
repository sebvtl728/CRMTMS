<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Signatures\SignatureDrawing;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Online attendance signing (spec 2026-11-20 émargement): the trainer opens
 * a half-day of a session on its own day behind a shared link (QR code;
 * only the SHA-256 of the 40-character token is kept), the trainees expected
 * that day pick their name and draw their signature, the trainer validates
 * (validate(), in SlotValidation). Every write locks the slot row.
 */
final class AttendanceSigning
{
    public const NOT_TODAY = 'L\'émargement en ligne s\'ouvre le jour même.';

    public const CANCELLED_SESSION = 'Cette session est annulée.';

    public const ALREADY_VALIDATED = 'Cette demi-journée est déjà validée.';

    public const ALREADY_SIGNED = 'Ce nom a déjà signé.';

    public const NOT_OPEN = 'L\'émargement de cette demi-journée est fermé.';

    public const UNKNOWN_TRAINEE = 'Stagiaire introuvable sur cette demi-journée.';

    /**
     * Opens (creates, reopens or renews) the half-day: a new token each
     * time, the previous one dead.
     *
     * @return array{slot: SignSlot, token: string}
     *
     * @throws UserFacingHttpException
     */
    public function open(TrainingSession $session, string $date, string $half, User $by): array
    {
        if (! in_array($half, SignSlot::HALVES, true)) {
            throw ValidationException::withMessages(['half' => 'Demi-journée inconnue.']);
        }
        if ($date !== TrainingSession::today()->toDateString()) {
            throw new UserFacingHttpException(409, self::NOT_TODAY);
        }
        $token = Str::random(40);
        $slot = DB::transaction(function () use ($session, $date, $half, $by, $token): SignSlot {
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED_SESSION);
            }
            if (SlotSeances::of($locked->id, $date, $half)->isEmpty()) {
                throw new UserFacingHttpException(409, $half === SignSlot::MORNING ? 'Aucune séance ce matin.' : 'Aucune séance cet après-midi.');
            }
            Closures::assertOpen($locked->id, $date);
            $slot = SignSlot::query()->where('training_session_id', $locked->id)->where('date', $date)->where('half', $half)->lockForUpdate()->first();
            if ($slot?->isValidated()) {
                throw new UserFacingHttpException(409, self::ALREADY_VALIDATED);
            }
            $slot ??= new SignSlot(['training_session_id' => $locked->id, 'date' => $date, 'half' => $half]);
            $slot->fill([
                'status' => SignSlot::OPEN,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => $token,
                'opened_at' => CarbonImmutable::now(),
                'opened_by' => $by->id,
            ])->save();

            return $slot;
        });

        return ['slot' => $slot, 'token' => $token];
    }

    public function close(SignSlot $slot): void
    {
        DB::transaction(function () use ($slot): void {
            $locked = SignSlot::query()->lockForUpdate()->findOrFail($slot->id);
            if ($locked->isValidated()) {
                throw new UserFacingHttpException(409, self::ALREADY_VALIDATED);
            }
            $locked->fill(['status' => SignSlot::CLOSED, 'token_hash' => null, 'token_encrypted' => null])->save();
        });
    }

    /**
     * The open slot of a link, today (Paris), of a live session; null in
     * every other case (unknown, malformed, closed, validated, another day,
     * session cancelled or in the trash) - the caller answers one 404.
     */
    public static function findByToken(string $token): ?SignSlot
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $slot = SignSlot::query()->where('token_hash', hash('sha256', $token))->first();
        if ($slot === null || $slot->status !== SignSlot::OPEN || $slot->date->toDateString() !== TrainingSession::today()->toDateString()) {
            return null;
        }
        $session = TrainingSession::query()->find($slot->training_session_id);

        return $session === null || $session->isCancelled() ? null : $slot;
    }

    /**
     * Records the drawn signature of an expected trainee on an open slot
     * (the link and the day are checked by the caller: findByToken).
     *
     * @param  string  $drawing  PNG data URI (SignatureDrawing)
     *
     * @throws ValidationException
     * @throws UserFacingHttpException
     */
    public function sign(SignSlot $slot, string $enrolmentId, string $drawing, string $ip, string $agent, string $source = AttendanceSignature::ONLINE, ?CarbonImmutable $signedAt = null, bool $requireOpen = true): AttendanceSignature
    {
        $png = $drawing === '' && $source !== AttendanceSignature::ONLINE ? null : SignatureDrawing::decode($drawing);
        $path = null;
        try {
            return DB::transaction(function () use ($slot, $enrolmentId, $png, $ip, $agent, $source, $signedAt, $requireOpen, &$path): AttendanceSignature {
                $locked = SignSlot::query()->lockForUpdate()->findOrFail($slot->id);
                if ($locked->isValidated() || ($requireOpen && $locked->status !== SignSlot::OPEN)) {
                    throw new UserFacingHttpException(409, $locked->isValidated() ? self::ALREADY_VALIDATED : self::NOT_OPEN);
                }
                $enrolment = Str::isUuid($enrolmentId) ? SlotSeances::expected($locked)->firstWhere('id', $enrolmentId) : null;
                if ($enrolment === null) {
                    throw ValidationException::withMessages(['enrolment_id' => self::UNKNOWN_TRAINEE]);
                }
                if (AttendanceSignature::query()->where('slot_id', $locked->id)->where('enrolment_id', $enrolment->id)->exists()) {
                    throw new UserFacingHttpException(409, self::ALREADY_SIGNED);
                }
                $signature = new AttendanceSignature([
                    'slot_id' => $locked->id,
                    'enrolment_id' => $enrolment->id,
                    'signed_at' => $signedAt ?? CarbonImmutable::now(),
                    'source' => $source,
                    'ip' => $ip === '' ? null : mb_substr($ip, 0, 45),
                    'user_agent' => $agent === '' ? null : mb_substr($agent, 0, 300),
                ]);
                $signature->id = (string) Str::uuid7();
                if ($png !== null) {
                    $path = self::drawingPath($locked, $signature->id);
                    Storage::disk(TenantStorage::DISK)->put($path, $png);
                    $signature->drawing_path = $path;
                    $signature->drawing_sha256 = hash('sha256', $png);
                }
                $signature->save();

                return $signature;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk(TenantStorage::DISK)->delete($path);
            }

            throw $e;
        }
    }

    /** A signature made under the wrong name, before validation: line and drawing removed. */
    public function erase(AttendanceSignature $signature): void
    {
        $path = DB::transaction(function () use ($signature): ?string {
            $slot = SignSlot::query()->lockForUpdate()->findOrFail($signature->slot_id);
            if ($slot->isValidated()) {
                throw new UserFacingHttpException(409, self::ALREADY_VALIDATED);
            }
            $row = AttendanceSignature::query()->lockForUpdate()->findOrFail($signature->id);
            $path = $row->drawing_path;
            $row->delete();

            return $path;
        });
        if ($path !== null) {
            Storage::disk(TenantStorage::DISK)->delete($path);
        }
    }

    /**
     * « Léa D. » for each enrolment (first name, initial of the last name),
     * homonyms numbered « Léa D. (2) » in the order of the enrolments given.
     *
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, string> enrolment id => label
     */
    public static function publicNames(Collection $enrolments): array
    {
        $people = app(Fiches::class)->personLabels($enrolments->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $seen = [];
        $names = [];
        foreach ($enrolments->sortBy('id') as $e) {
            $p = $people[$e->person_id] ?? null;
            $first = trim((string) ($p['first_name'] ?? ''));
            $last = trim((string) ($p['last_name'] ?? ''));
            $label = trim($first.' '.($last === '' ? '' : mb_strtoupper(mb_substr($last, 0, 1)).'.'));
            $label = $label === '' ? 'Stagiaire' : $label;
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $names[$e->id] = $seen[$label] === 1 ? $label : $label.' ('.$seen[$label].')';
        }

        return $names;
    }

    public static function drawingPath(SignSlot $slot, string $name): string
    {
        return TenantStorage::path('attendance_signatures', $slot->training_session_id.'/'.$slot->id.'/'.$name.'.png');
    }
}
