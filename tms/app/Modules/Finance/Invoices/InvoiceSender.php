<?php

namespace App\Modules\Finance\Invoices;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Reminders\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Envoyer » / « Renvoyer » an issued invoice or credit note by e-mail: one
 * InvoiceSending per request (recipient, subject, message), queued after
 * commit as SendInvoice; the invoice's email_status becomes « pending ».
 * Only the columns the immutability trigger allows are written on the
 * invoice (email_status, email_error_code, updated_at). The audit entry
 * names the sending, never the address.
 */
final class InvoiceSender
{
    public const DRAFT = 'Seule une facture ou un avoir validé peut être envoyé.';

    public const STILL_SENDING = 'Un envoi est déjà en cours pour cette pièce.';

    public const NO_ARCHIVE = 'Le PDF archivé de la pièce est introuvable : elle ne peut pas être envoyée.';

    public const NOTHING_TO_RESEND = 'Aucun envoi échoué à renouveler.';

    public function __construct(private readonly InvoicePdf $pdf, private readonly AuditRecorder $audit) {}

    /**
     * @param  array{to: string, subject: string, body: string}  $message
     */
    public function send(User $user, Invoice $invoice, array $message): InvoiceSending
    {
        return $this->queue($user, $invoice, $message);
    }

    /**
     * Queues one sending of the archived PDF under the lock of the invoice:
     * the invoice itself, or a reminder of $level (0.9.1; $check runs under
     * the lock and may refuse). $user null: an automatic reminder.
     *
     * @param  array{to: string, subject: string, body: string}  $message
     * @param  (callable(Invoice): void)|null  $check
     */
    public function queue(?User $user, Invoice $invoice, array $message, ?int $level = null, ?callable $check = null): InvoiceSending
    {
        return DB::transaction(function () use ($user, $invoice, $message, $level, $check): InvoiceSending {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->guard($locked);
            if ($check !== null) {
                $check($locked);
            }

            $sending = InvoiceSending::query()->create([
                'invoice_id' => $locked->id,
                'email' => $message['to'],
                'subject' => $message['subject'],
                'body' => $message['body'],
                'status' => 'pending',
                'sent_by' => $user?->id,
                'purpose' => $level === null ? InvoiceSending::INVOICE : InvoiceSending::REMINDER,
                'reminder_level' => $level,
            ]);
            Invoice::query()->whereKey($locked->id)->toBase()
                ->update(['email_status' => 'pending', 'email_error_code' => null, 'updated_at' => CarbonImmutable::now()]);
            $this->audit->record($level === null ? 'invoice.email_queued' : 'invoice.reminder_queued', $locked, [], ['sending' => $sending->id, ...($level === null ? [] : ['level' => $level])], $user);
            SendInvoice::dispatch($sending->id)->afterCommit();

            $invoice->setRawAttributes(Invoice::query()->findOrFail($locked->id)->getAttributes(), true);

            return $sending;
        });
    }

    /** « Renvoyer »: the last message again, after the e-mail did not leave. */
    public function resend(User $user, Invoice $invoice): InvoiceSending
    {
        $last = InvoiceSending::query()->where('invoice_id', $invoice->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($invoice->email_status !== 'failed' || $last === null || $last->status !== 'failed') {
            throw new UserFacingHttpException(409, self::NOTHING_TO_RESEND);
        }

        $message = ['to' => $last->email, 'subject' => $last->subject, 'body' => $last->body];
        if ($last->purpose === InvoiceSending::REMINDER) {
            // A reminder again only while that very level is still due (not paid, paused nor overtaken meanwhile).
            return app(Reminders::class)->send($user, $invoice, $message, $last->reminder_level);
        }

        return $this->queue($user, $invoice, $message);
    }

    private function guard(Invoice $invoice): void
    {
        if ($invoice->isDraft()) {
            throw new UserFacingHttpException(409, self::DRAFT);
        }
        if ($invoice->email_status === 'pending') {
            throw new UserFacingHttpException(409, self::STILL_SENDING);
        }
        if ($this->pdf->archived($invoice) === null) {
            throw new UserFacingHttpException(409, self::NO_ARCHIVE);
        }
    }
}
