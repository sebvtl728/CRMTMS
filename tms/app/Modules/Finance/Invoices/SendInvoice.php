<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one InvoiceSending (recipient, subject, message typed in the
 * window) with the ARCHIVED PDF of the invoice, through the organisme's
 * mailer (TenantMailer, the company's SMTP first). A replay of a sending
 * that is no longer pending does nothing.
 *
 * Only the archive is ever sent: without it, or when its bytes no longer
 * match the fingerprint taken at validation, nothing leaves and the sending
 * fails at once (never a PDF rendered again).
 *
 * Failures are rethrown as InvoiceMailFailed (fixed French reason and SMTP
 * code only, never the server's text). Three tries with a backoff, then the
 * sending and the invoice's email_status become « failed »: the page offers
 * « Renvoyer ». A refusal a retry would repeat (PermanentDeliveryFailure)
 * fails at once. On success the invoice goes validated → sent (the only
 * columns the immutability trigger lets change: status, sent_at,
 * email_status, email_error_code, updated_at: never updated_by).
 */
class SendInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const NO_DOCUMENT = 'Le PDF archivé de la pièce est introuvable : l\'e-mail n\'est pas parti.';

    public const ALTERED_DOCUMENT = 'Le PDF archivé ne correspond plus à son empreinte : l\'e-mail n\'est pas parti.';

    public int $tries = 3;

    public function __construct(public string $sendingId)
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

    public function handle(InvoicePdf $pdf, AuditRecorder $audit): void
    {
        $sending = InvoiceSending::query()->find($this->sendingId);
        $invoice = $sending === null ? null : Invoice::query()->with('company')->find($sending->invoice_id);
        if ($sending === null || $invoice === null || $sending->status !== 'pending' || $invoice->isDraft()) {
            return;
        }

        $archived = $pdf->archived($invoice);
        if ($archived === null) {
            Log::warning('Archive PDF de la facture introuvable', ['invoice_id' => $invoice->id]);
            $this->failed(new InvoiceMailFailed(self::NO_DOCUMENT, 0), 'no_archive');

            return;
        }
        $bytes = (string) Storage::disk(TenantStorage::DISK)->get($archived);
        if ($invoice->pdf_sha256 !== null && ! hash_equals($invoice->pdf_sha256, hash('sha256', $bytes))) {
            Log::warning('Archive PDF de la facture altérée', ['invoice_id' => $invoice->id]);
            $this->failed(new InvoiceMailFailed(self::ALTERED_DOCUMENT, 0), 'archive_altered');

            return;
        }

        $error = null;
        $send = function () use ($invoice, $sending, $bytes, &$error): string {
            try {
                TenantMailer::for($invoice->company)
                    ->to($sending->email)
                    ->send(new InvoiceMail($sending->subject, $sending->body, $bytes, InvoicePdf::fileName($invoice)));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return $invoice->title().' envoyé par e-mail.';
        };

        $config = TenantMailer::activeConfig($invoice->company);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'invoice:'.$invoice->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error set by the closure */
        if ($error !== null) {
            $failure = new InvoiceMailFailed(self::reason($error), self::smtpCode($error));
            if ($this->job instanceof SyncJob || $error instanceof PermanentDeliveryFailure) {
                // No worker (sync queue), or a refusal a retry would repeat: the page shows the banner.
                $this->failed($failure, self::code($error));

                return;
            }

            throw $failure; // retried (backoff), then failed()
        }

        $now = CarbonImmutable::now();
        DB::transaction(function () use ($sending, $invoice, $now): void {
            $sending->forceFill(['status' => 'sent', 'sent_at' => $now, 'error' => null, 'error_code' => null])->save();
            // Only the columns the trigger lets change; sent_at keeps the first sending.
            Invoice::query()->whereKey($invoice->id)->toBase()->update([
                'status' => InvoiceStatus::Sent->value,
                'sent_at' => $invoice->sent_at ?? $now,
                'email_status' => 'sent',
                'email_error_code' => null,
                'updated_at' => $now,
            ]);
        });
        $audit->record($sending->purpose === InvoiceSending::REMINDER ? 'invoice.reminder_sent' : 'invoice.email_sent', $invoice, [], ['sending' => $sending->id, ...($sending->reminder_level === null ? [] : ['level' => $sending->reminder_level])], self::sender($sending));
    }

    /**
     * Every try failed (or a failure a retry would repeat): the invoice shows
     * « L'e-mail n'est pas parti » and offers « Renvoyer ».
     */
    public function failed(?Throwable $e, ?string $code = null): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () use ($e, $code): void {
            $sending = InvoiceSending::query()->where('status', 'pending')->find($this->sendingId);
            if ($sending === null) {
                return;
            }
            $reason = $e === null ? self::reason(new RuntimeException) : self::reason($e);
            $code ??= $e === null ? 'unreachable' : self::code($e);
            $sending->forceFill(['status' => 'failed', 'error' => $reason, 'error_code' => $code])->save();
            Invoice::query()->whereKey($sending->invoice_id)->toBase()
                ->update(['email_status' => 'failed', 'email_error_code' => $code, 'updated_at' => CarbonImmutable::now()]);
            $invoice = Invoice::query()->find($sending->invoice_id);
            app(AuditRecorder::class)->record('invoice.email_failed', $invoice, [], ['sending' => $sending->id, 'code' => $code], self::sender($sending));
        });
    }

    private static function sender(InvoiceSending $sending): ?User
    {
        return $sending->sent_by === null ? null : User::query()->find($sending->sent_by);
    }

    /**
     * A fixed French reason: the SMTP reply code at most, never the server's
     * text (it may quote the address or the account).
     */
    public static function reason(Throwable $e): string
    {
        if ($e instanceof InvoiceMailFailed || $e instanceof PermanentDeliveryFailure) {
            return $e->getMessage(); // fixed French texts, never the server's
        }
        if ($e instanceof SmtpHostRefused) {
            return 'L\'adresse du serveur d\'envoi est refusée (adresse interne).';
        }
        $code = self::smtpCode($e);

        return $code !== 0
            ? "Le serveur d'envoi a refusé le message (code {$code})."
            : 'Le serveur d\'envoi est injoignable ou a refusé le message.';
    }

    /** Short code kept on the invoice (email_error_code), 40 characters at most. */
    public static function code(Throwable $e): string
    {
        return match (true) {
            $e instanceof SmtpHostRefused => 'host_refused',
            $e instanceof PermanentDeliveryFailure => 'refused',
            self::smtpCode($e) !== 0 => 'smtp_'.self::smtpCode($e),
            default => 'unreachable',
        };
    }

    /**
     * The SMTP reply code (4xx / 5xx) of a transport failure, else 0.
     */
    public static function smtpCode(Throwable $e): int
    {
        if ($e instanceof InvoiceMailFailed) {
            return $e->getCode();
        }
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
