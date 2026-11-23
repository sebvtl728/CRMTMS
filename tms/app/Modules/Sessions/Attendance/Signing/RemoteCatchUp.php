<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Signatures\SignatureDrawing;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The remote catch-up of a half-day (2026-11-21): an attendance manager
 * sends one trainee a personal link (7 days, single use, only its SHA-256
 * kept; a new one revokes the previous), the trainee asks for a 6-digit code
 * by e-mail (10 minutes, 5 tries, one per minute, HMAC as Core\Signatures),
 * draws and signs. The signature waits for a confirmation.
 */
final class RemoteCatchUp
{
    public const LINK_DAYS = 7;

    public const CODE_MINUTES = 10;

    public const CODE_TRIES = 5;

    public const ALREADY_SIGNED = 'Ce stagiaire a déjà signé cette demi-journée.';

    public const EMAIL = 'Indiquez une adresse e-mail valide.';

    public const WAIT = 'Patientez une minute avant de demander un nouveau code.';

    public const NO_CODE = 'Demandez d\'abord votre code.';

    public const EXPIRED = 'Ce code a expiré : demandez-en un nouveau.';

    public const TOO_MANY = 'Trop d\'essais : demandez un nouveau code.';

    public const WRONG = 'Code incorrect.';

    public const NOTHING_LEFT = 'Toutes ces demi-journées sont déjà signées.';

    public const MAIL_FAILED = 'Le code n\'a pas pu être envoyé : réessayez dans un instant.';

    public function __construct(private readonly AttendanceSigning $signing, private readonly MailTemplates $templates) {}

    /**
     * @throws ValidationException
     * @throws UserFacingHttpException
     */
    public function send(SignSlot $slot, string $enrolmentId, string $email, User $by): CatchUpLink
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw ValidationException::withMessages(['email' => self::EMAIL]);
        }
        $token = Str::random(40);
        $link = DB::transaction(function () use ($slot, $enrolmentId, $email, $by, $token): CatchUpLink {
            $session = TrainingSession::query()->lockForUpdate()->findOrFail($slot->training_session_id);
            if ($session->isCancelled()) {
                throw new UserFacingHttpException(409, AttendanceSigning::CANCELLED_SESSION);
            }
            $locked = SignSlot::query()->lockForUpdate()->findOrFail($slot->id);
            Closures::assertOpen($locked->training_session_id, $locked->date->toDateString());
            $enrolment = Str::isUuid($enrolmentId) ? SlotSeances::expected($locked)->firstWhere('id', $enrolmentId) : null;
            if ($enrolment === null) {
                throw ValidationException::withMessages(['enrolment_id' => AttendanceSigning::UNKNOWN_TRAINEE]);
            }
            if (AttendanceSignature::query()->where('slot_id', $locked->id)->where('enrolment_id', $enrolment->id)->exists()) {
                throw ValidationException::withMessages(['enrolment_id' => self::ALREADY_SIGNED]);
            }
            CatchUpLink::query()->where('slot_id', $locked->id)->where('enrolment_id', $enrolment->id)
                ->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);

            $link = CatchUpLink::query()->create([
                'slot_id' => $locked->id,
                'enrolment_id' => $enrolment->id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => CarbonImmutable::now()->addDays(self::LINK_DAYS),
                'created_by' => $by->id,
            ]);
            $link->forceFill(['email_status' => 'pending'])->save();

            return $link;
        });
        SendCatchUpMail::dispatch($link->id, $token)->afterCommit();

        return $link;
    }

    /**
     * One link for several half-days of the trainee (GroupCatchUp checked
     * them): each must still be signable; the active links of these
     * half-days for the trainee are revoked.
     *
     * @param  list<string>  $slotIds
     *
     * @throws ValidationException
     */
    public function sendGroup(array $slotIds, string $enrolmentId, string $email, User $by): CatchUpLink
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw ValidationException::withMessages(['email' => self::EMAIL]);
        }
        $token = Str::random(40);
        $link = DB::transaction(function () use ($slotIds, $enrolmentId, $email, $by, $token): CatchUpLink {
            $slots = SignSlot::query()->lockForUpdate()->whereKey($slotIds)->get()->keyBy('id');
            foreach ($slotIds as $id) {
                $slot = $slots[$id] ?? throw ValidationException::withMessages(['halves' => AttendanceSigning::UNKNOWN_TRAINEE]);
                Closures::assertOpen($slot->training_session_id, $slot->date->toDateString());
                if (! SlotSeances::expected($slot)->contains('id', $enrolmentId)) {
                    throw ValidationException::withMessages(['enrolment_id' => AttendanceSigning::UNKNOWN_TRAINEE]);
                }
                if (AttendanceSignature::query()->where('slot_id', $id)->where('enrolment_id', $enrolmentId)->exists()) {
                    throw ValidationException::withMessages(['halves' => self::ALREADY_SIGNED]);
                }
            }
            foreach (CatchUpLink::query()->where('enrolment_id', $enrolmentId)->whereNull('used_at')->whereNull('revoked_at')->get() as $other) {
                if (array_intersect($other->slotIds(), $slotIds) !== []) {
                    $other->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
                }
            }
            $link = CatchUpLink::query()->create([
                'slot_id' => $slotIds[0],
                'extra_slot_ids' => array_slice($slotIds, 1) ?: null,
                'enrolment_id' => $enrolmentId,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => CarbonImmutable::now()->addDays(self::LINK_DAYS),
                'created_by' => $by->id,
            ]);
            $link->forceFill(['email_status' => 'pending'])->save();

            return $link;
        });
        SendCatchUpMail::dispatch($link->id, $token)->afterCommit();

        return $link;
    }

    /**
     * The half-days of the link the trainee can still sign: month open,
     * session live, trainee expected, not signed yet.
     *
     * @return list<SignSlot>
     */
    public static function signableSlots(CatchUpLink $link): array
    {
        $out = [];
        foreach (SignSlot::query()->whereKey($link->slotIds())->orderBy('date')->orderBy('half', 'desc')->get() as $slot) {
            $session = TrainingSession::query()->find($slot->training_session_id);
            if ($session === null || $session->isCancelled()) {
                continue;
            }
            $closed = AttendanceClosure::query()->where('training_session_id', $slot->training_session_id)
                ->where('month', Months::of($slot->date->toDateString())->toDateString())->whereNotNull('closed_at')->exists();
            $signed = AttendanceSignature::query()->where('slot_id', $slot->id)->where('enrolment_id', $link->enrolment_id)->exists();
            if (! $closed && ! $signed && SlotSeances::expected($slot)->contains('id', $link->enrolment_id)) {
                $out[] = $slot;
            }
        }

        return $out;
    }

    /**
     * The live link of a token; null for an unknown, malformed, used,
     * revoked or expired link, a closed month, a cancelled or trashed
     * session, a trainee no more expected or already signed: one 404.
     */
    public static function find(string $token): ?CatchUpLink
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $link = CatchUpLink::query()->where('token_hash', hash('sha256', $token))->first();
        if ($link === null || ! $link->isActive()) {
            return null;
        }

        // A grouped link lives while one of its half-days can still be signed.
        return self::signableSlots($link) === [] ? null : $link;
    }

    public function sendCode(CatchUpLink $link): void
    {
        $code = DB::transaction(function () use ($link): string {
            $locked = CatchUpLink::query()->lockForUpdate()->findOrFail($link->id);
            if ($locked->code_sent_at !== null && $locked->code_sent_at->addMinute()->isFuture()) {
                throw new UserFacingHttpException(429, self::WAIT);
            }
            $code = sprintf('%06d', random_int(0, 999999));
            $locked->forceFill([
                'code_hash' => self::codeHash($locked, $code),
                'code_expires_at' => CarbonImmutable::now()->addMinutes(self::CODE_MINUTES),
                'code_attempts' => 0,
                'code_sent_at' => CarbonImmutable::now(),
            ])->save();

            return $code;
        });
        $text = $this->templates->render(CatchUpMailTemplates::CODE, [
            'code' => $code,
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
        ]);
        try {
            TenantMailer::for(null)->to((string) $link->email)->send(new CatchUpMail($text['subject'], $text['body']));
        } catch (Throwable $e) {
            Log::warning('Catch-up code e-mail failed.', ['error' => $e::class]);

            throw new UserFacingHttpException(503, self::MAIL_FAILED);
        }
    }

    /**
     * Checks the drawing (a refused drawing costs no try), then the code,
     * then signs the half-day for the trainee of the link.
     *
     * @throws ValidationException
     */
    public function sign(CatchUpLink $link, string $code, string $drawing, string $ip, string $agent): AttendanceSignature
    {
        SignatureDrawing::decode($drawing);
        $signature = DB::transaction(function () use ($link, $code, $drawing, $ip, $agent): ?AttendanceSignature {
            $locked = CatchUpLink::query()->lockForUpdate()->findOrFail($link->id);
            if ($locked->code_hash === null) {
                throw ValidationException::withMessages(['code' => self::NO_CODE]);
            }
            if ($locked->code_attempts >= self::CODE_TRIES) {
                throw ValidationException::withMessages(['code' => self::TOO_MANY]);
            }
            if ($locked->code_expires_at === null || ! $locked->code_expires_at->isFuture()) {
                throw ValidationException::withMessages(['code' => self::EXPIRED]);
            }
            if (! hash_equals((string) $locked->code_hash, self::codeHash($locked, trim($code)))) {
                // The try is counted (committed), then refused below.
                $locked->forceFill(['code_attempts' => $locked->code_attempts + 1])->save();

                return null;
            }
            // Signed and used together: a refused signature never burns the link. One drawing for every half-day of the link.
            $signature = null;
            foreach (self::signableSlots($locked) as $slot) {
                $signature = $this->signing->sign($slot, $locked->enrolment_id, $drawing, $ip, $agent, requireOpen: false, kind: AttendanceSignature::KIND_REMOTE);
            }
            if ($signature === null) {
                throw ValidationException::withMessages(['code' => self::NOTHING_LEFT]);
            }
            $locked->forceFill(['used_at' => CarbonImmutable::now(), 'code_hash' => null])->save();

            return $signature;
        });
        if ($signature === null) {
            throw ValidationException::withMessages(['code' => self::WRONG]);
        }

        return $signature;
    }

    private static function codeHash(CatchUpLink $link, string $code): string
    {
        return hash_hmac('sha256', $link->id.'|'.$code, (string) config('app.key'));
    }
}
