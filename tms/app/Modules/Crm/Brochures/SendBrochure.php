<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Catalogue\Brochures;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Mail365\GraphMailFailed;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Quotes\SendQuote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends one « Envoyer une plaquette » e-mail through the organisme's mailer
 * (TenantMailer) with the brochure versions recorded for it. Reads the
 * CURRENT row: a replay after success (status no longer pending) does
 * nothing. A file missing from the disk fails at once (no retry).
 *
 * Failures are rethrown as BrochureMailFailed (reason and SMTP code only);
 * three tries with a backoff, then status « failed » and a fixed French
 * reason, never the server's text; a refusal Microsoft 365 would repeat
 * (GraphMailFailed) fails at once. With an SMTP or Microsoft 365 connector
 * each try is journaled in connector_runs with the sending's id only.
 */
class SendBrochure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const ANONYMISED = 'Le destinataire a été anonymisé : l\'e-mail n\'est pas parti.';

    public int $tries = 3;

    public function __construct(public string $sendingId)
    {
        $this->captureTenant();
    }

    /**
     * @return list<int> seconds before the 2nd and the 3rd (last) try
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(Brochures $brochures): void
    {
        $sending = BrochureSending::query()->find($this->sendingId);
        if ($sending === null || $sending->email_status !== BrochureSending::PENDING) {
            return;
        }
        // Cleared by the anonymisation of the recipient before the job ran: nothing to send.
        if ($sending->recipient_email === null || $sending->subject === null || $sending->message === null) {
            $this->failed(new BrochureMailFailed(self::ANONYMISED));

            return;
        }

        $files = BrochureAttachments::read($brochures->versions($sending->versionIds()));
        if ($files === null || $files === []) {
            Log::warning('Fichier de plaquette introuvable', ['brochure_sending_id' => $sending->id]);
            $this->failed(new BrochureMailFailed(BrochureAttachments::MISSING));

            return;
        }

        $error = null;
        $send = function () use ($sending, $files, &$error): string {
            try {
                TenantMailer::for(null)->to($sending->recipient_email)->send(new BrochureMail((string) $sending->subject, (string) $sending->message, $files));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return 'Plaquette envoyée par e-mail.';
        };

        $config = TenantMailer::activeConfig(null);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'brochure:'.$sending->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error set by the closure */
        if ($error !== null) {
            $failure = new BrochureMailFailed(self::reason($error), SendQuote::smtpCode($error));
            if ($this->job instanceof SyncJob || $error instanceof GraphMailFailed) {
                // No worker (sync queue), or a refusal a retry would repeat: the history shows « Échec ».
                $this->failed($failure);

                return;
            }

            throw $failure; // retried (backoff), then failed()
        }

        BrochureSending::query()->whereKey($sending->id)->toBase()
            ->update(['email_status' => BrochureSending::SENT, 'sent_at' => CarbonImmutable::now(), 'email_error' => null]);
    }

    /**
     * Every try failed: the history shows « Échec » with a fixed reason.
     */
    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->run($tenant, fn () => BrochureSending::query()->whereKey($this->sendingId)->where('email_status', BrochureSending::PENDING)->toBase()
            ->update(['email_status' => BrochureSending::FAILED, 'email_error' => self::reason($e ?? new RuntimeException)]));
    }

    /**
     * A fixed French reason: the SMTP reply code at most, never the
     * server's text.
     */
    public static function reason(Throwable $e): string
    {
        return $e instanceof BrochureMailFailed ? $e->getMessage() : SendQuote::reason($e);
    }
}
