<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Catalogue\Brochures;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Mail365\GraphMailFailed;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Brochures\BrochureAttachments;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends the last e-mail of a quote (sending or reminder, quotes.email_message)
 * with its archived PDF, through the organisme's mailer (TenantMailer, the
 * company's SMTP first). Reads the CURRENT quote: a replay after success, or
 * a message already sent, does nothing (email_status is no longer pending).
 *
 * Without its archived PDF (purged by the anonymisation, or lost) nothing
 * is sent: email_status « failed » at once, no PDF rendered again.
 *
 * Failures are rethrown as QuoteMailFailed (reason and SMTP code only).
 * Three tries with a backoff, then email_status « failed » and a fixed
 * French reason (the SMTP code at most): the quote stays « Envoyé » and its
 * page offers « Renvoyer ». A refusal Microsoft 365 would repeat
 * (GraphMailFailed: permission, secret) fails at once, without retry. With
 * an SMTP or Microsoft 365 connector, each try is journaled in
 * connector_runs with the quote number only, never an address nor the
 * server's text.
 */
class SendQuote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const NO_BROCHURE = BrochureAttachments::MISSING;

    public const NO_DOCUMENT = 'Le PDF archivé du devis est introuvable : l\'e-mail n\'est pas parti.';

    public int $tries = 3;

    public function __construct(public string $quoteId)
    {
        $this->captureTenant();
    }

    /**
     * @return list<int> seconds before the 2nd and 3rd tries
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(QuotePdf $pdf, Brochures $brochures): void
    {
        $quote = Quote::query()->with('company')->find($this->quoteId);
        $message = $quote?->email_message;
        if ($quote === null || $quote->email_status !== 'pending' || ! is_array($message) || ! isset($message['to'], $message['subject'], $message['body'])) {
            return;
        }

        // Only the archived document is sent, never one rendered again with
        // today's data (purged by the anonymisation, or lost): no retry.
        $archived = $pdf->archived($quote);
        if ($archived === null) {
            Log::warning('Archive PDF du devis introuvable', ['quote_id' => $quote->id]);
            $this->failed(new QuoteMailFailed(self::NO_DOCUMENT));

            return;
        }

        // The versions recorded at sending, whatever became of their brochure since.
        $versions = $brochures->versions(QuoteBrochure::query()->where('quote_id', $quote->id)->orderBy('position')->pluck('brochure_version_id')->all());
        $joined = BrochureAttachments::read($versions);
        if ($joined === null) {
            Log::warning('Fichier de plaquette introuvable', ['quote_id' => $quote->id]);
            $this->failed(new QuoteMailFailed(self::NO_BROCHURE));

            return;
        }

        $error = null;
        $send = function () use ($quote, $message, $archived, $joined, &$error): string {
            try {
                $bytes = (string) Storage::disk(TenantStorage::DISK)->get($archived);

                TenantMailer::for($quote->company)
                    ->to($message['to'])
                    ->cc($message['cc'] ?? [])
                    ->send(new QuoteMail($message['subject'], $message['body'], $bytes, QuotePdf::fileName($quote), $joined));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return ($message['kind'] ?? null) === QuoteMail::KIND_REMIND
                ? "Relance du devis {$quote->number} envoyée par e-mail."
                : "Devis {$quote->number} envoyé par e-mail.";
        };

        $config = TenantMailer::activeConfig($quote->company);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'quote:'.$quote->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error set by the closure */
        if ($error !== null) {
            // Never the transport's own text: it may quote the address or the account.
            $failure = new QuoteMailFailed(self::reason($error), self::smtpCode($error));
            if ($this->job instanceof SyncJob || $error instanceof GraphMailFailed) {
                // No worker (sync queue), or a refusal a retry would repeat: the page shows the banner.
                $this->failed($failure);

                return;
            }

            throw $failure; // retried (backoff), then failed()
        }

        Quote::query()->whereKey($quote->id)->toBase()
            ->update(['email_status' => 'sent', 'email_sent_at' => CarbonImmutable::now(), 'email_error' => null]);
    }

    /**
     * Every try failed: the quote shows « L'e-mail n'est pas parti ».
     */
    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->run($tenant, fn () => Quote::query()->whereKey($this->quoteId)->where('email_status', 'pending')->toBase()
            ->update(['email_status' => 'failed', 'email_error' => $e === null ? self::reason(new RuntimeException) : self::reason($e)]));
    }

    /**
     * A fixed French reason: the SMTP reply code at most, never the server's
     * text (it may quote the address or the account).
     */
    public static function reason(Throwable $e): string
    {
        if ($e instanceof QuoteMailFailed || $e instanceof GraphMailFailed) {
            return $e->getMessage(); // fixed French texts, never the server's
        }
        if ($e instanceof SmtpHostRefused) {
            return 'L\'adresse du serveur d\'envoi est refusée (adresse interne).';
        }
        if ($e instanceof PdfRenderingFailed) {
            return 'Le PDF du devis n\'a pas pu être produit.';
        }
        $code = self::smtpCode($e);

        return $code !== 0
            ? "Le serveur d'envoi a refusé le message (code {$code})."
            : 'Le serveur d\'envoi est injoignable ou a refusé le message.';
    }

    /**
     * The SMTP reply code (4xx / 5xx) of a transport failure, else 0.
     */
    public static function smtpCode(Throwable $e): int
    {
        if (! $e instanceof TransportExceptionInterface && ! $e instanceof RuntimeException) {
            return 0;
        }
        $code = (int) $e->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        return preg_match('/got code "([45]\d\d)"/', $e->getMessage(), $m) === 1 || preg_match('/^\s*([45]\d\d)\b/', $e->getMessage(), $m) === 1
            ? (int) $m[1] : 0;
    }
}
