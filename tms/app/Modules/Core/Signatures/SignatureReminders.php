<?php

namespace App\Modules\Core\Signatures;

use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The automatic reminders of a pending online signature (2026-11-22): a
 * request still pending and unexpired, that its module still accepts, with
 * an e-mail, whose last sending is N days old (AutomationSettings, 0 = off)
 * gets a new link (only its SHA-256 kept: the old link dies) at most
 * signatureReminderMax() times; never while the signer asked for a code in
 * the last 24 hours. Claim, new token and e-mail go together: a refused
 * e-mail rolls everything back (the old link still works).
 */
final class SignatureReminders
{
    public function __construct(private readonly MailTemplates $templates) {}

    /** The reminders sent. */
    public function run(CarbonImmutable $now): int
    {
        $days = AutomationSettings::signatureReminderDays();
        if ($days === 0) {
            return 0;
        }
        $max = AutomationSettings::signatureReminderMax();
        $due = $now->subDays($days);
        $sent = 0;
        $requests = SignatureRequest::query()->where('status', 'pending')->where('expires_at', '>', $now)
            ->where('reminders_sent', '<', $max)->whereNotNull('signer_email')->where('signer_email', '!=', '')
            ->whereRaw('coalesce(last_sent_at, created_at) <= ?', [$due])
            ->where(fn ($q) => $q->whereNull('code_sent_at')->orWhere('code_sent_at', '<', $now->subDay()))
            ->orderBy('created_at')->get();
        foreach ($requests as $request) {
            try {
                $sent += $this->remind($request, $now);
            } catch (Throwable $e) {
                Log::warning('Signature reminder failed.', ['request' => $request->id, 'error' => $e::class]);
            }
        }

        return $sent;
    }

    /**
     * The request may be reminded now: pending, unexpired, accepted by its
     * module, with an e-mail, under the maximum, its last sending N days old
     * and no code asked in the last 24 hours (the signer is signing).
     */
    public static function eligible(SignatureRequest $request, CarbonImmutable $now): bool
    {
        $days = AutomationSettings::signatureReminderDays();

        return $days > 0 && $request->status === 'pending' && $request->expires_at->isAfter($now)
            && $request->reminders_sent < AutomationSettings::signatureReminderMax()
            && is_string($request->signer_email) && $request->signer_email !== ''
            && ($request->last_sent_at ?? $request->created_at)->lte($now->subDays($days))
            && ($request->code_sent_at === null || $request->code_sent_at->lt($now->subDay()))
            && Signatures::refusal($request) === null;
    }

    /**
     * Under the row lock: checked again, claimed, new token saved and
     * committed; then the e-mail leaves outside the transaction (no lock held
     * while the mail server answers). A refused e-mail puts the old link back
     * and gives the claim back.
     */
    private function remind(SignatureRequest $request, CarbonImmutable $now): int
    {
        $token = Str::random(40);
        $prepared = DB::transaction(function () use ($request, $now, $token): ?array {
            $locked = SignatureRequest::query()->lockForUpdate()->findOrFail($request->id);
            $occurrence = (string) ($locked->reminders_sent + 1);
            if (! self::eligible($locked, $now) || ! AutomationSends::claim(AutomationSends::SIGNATURE_REMINDER, $locked->id, $occurrence)) {
                return null;
            }
            $previous = ['token_hash' => $locked->token_hash, 'reminders_sent' => $locked->reminders_sent, 'last_sent_at' => $locked->last_sent_at];
            $locked->forceFill(['token_hash' => hash('sha256', $token), 'reminders_sent' => $locked->reminders_sent + 1, 'last_sent_at' => $now])->save();

            return ['request' => $locked, 'previous' => $previous, 'occurrence' => $occurrence];
        });
        if ($prepared === null) {
            return 0;
        }
        $locked = $prepared['request'];
        $text = $this->templates->render(SignatureMailTemplates::REMINDER, [
            'document' => $locked->title, 'destinataire' => $locked->signer_name, 'lien' => url('/signature/'.$token),
            'echeance' => $locked->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
        ]);
        try {
            TenantMailer::for(null)->to((string) $locked->signer_email)->send(new SignatureMail($text['subject'], $text['body']));
        } catch (Throwable $e) {
            DB::transaction(function () use ($locked, $prepared): void {
                SignatureRequest::query()->whereKey($locked->id)->where('token_hash', $locked->token_hash)->update($prepared['previous']);
                AutomationSends::release(AutomationSends::SIGNATURE_REMINDER, $locked->id, $prepared['occurrence']);
            });

            throw $e;
        }

        return 1;
    }
}
