<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one DocumentSending with the ARCHIVED PDF of the document (checked
 * against its SHA-256) through the organisme's mailer. Three tries, then
 * « failed » with a fixed French reason (never the server's text). On
 * success the document is « Envoyé » and its dossier moves to « Convention
 * envoyée » (convention or contract), never backwards.
 */
class SendDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public string $sendingId)
    {
        $this->captureTenant();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AuditRecorder $audit, Paperwork $paperwork): void
    {
        $sending = DocumentSending::query()->find($this->sendingId);
        $document = $sending === null ? null : Document::query()->find($sending->document_id);
        if ($sending === null || $document === null || $sending->status !== 'pending' || $document->isCancelled()) {
            return;
        }
        try {
            $bytes = DocumentPdf::archived($document);
        } catch (UserFacingHttpException) {
            $this->failed(new DocumentMailFailed('Le PDF archivé du document est introuvable ou modifié : l\'e-mail n\'est pas parti.', 0), 'archive_altered');

            return;
        }
        $company = Company::query()->findOrFail($document->company_id);

        $error = null;
        $send = function () use ($company, $sending, $document, $bytes, &$error): string {
            try {
                TenantMailer::for($company)->to((string) $sending->email)
                    ->send(new DocumentMail((string) $sending->subject, (string) $sending->body, $bytes, $document->number.'.pdf'));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return 'Document '.$document->number.' envoyé par e-mail.';
        };
        $config = TenantMailer::activeConfig($company);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'document:'.$document->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error */
        if ($error !== null) {
            $failure = new DocumentMailFailed(self::reason($error), self::smtpCode($error));
            if ($this->job === null || $this->job instanceof SyncJob || $error instanceof PermanentDeliveryFailure) {
                $this->failed($failure, self::code($error));

                return;
            }

            throw $failure;
        }

        $now = CarbonImmutable::now();
        DB::transaction(function () use ($sending, $document, $now): void {
            $sending->forceFill(['status' => 'sent', 'sent_at' => $now, 'error' => null, 'error_code' => null])->save();
            Document::query()->whereKey($document->id)->toBase()->update([
                'status' => $document->status === DocumentStatus::Issued ? DocumentStatus::Sent->value : $document->status->value,
                'sent_at' => $document->sent_at ?? $now,
                'email_status' => 'sent',
                'email_error_code' => null,
                'updated_at' => $now,
            ]);
        });
        if (DocumentType::tryFrom($document->type)?->signable() ?? false) {
            $paperwork->markConventionSent($document->dossier_id, $now);
        }
        if ($document->type === DocumentType::Convocation->value && self::everyTraineeConvened($document->dossier_id)) {
            $paperwork->markConvocationsSent($document->dossier_id, $now);
        }
        $audit->record('document.email_sent', $document, [], ['sending' => $sending->id], self::sender($sending));
    }

    public function failed(?Throwable $e, ?string $code = null): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($e, $code): void {
            $sending = DocumentSending::query()->where('status', 'pending')->find($this->sendingId);
            if ($sending === null) {
                return;
            }
            $reason = $e === null ? self::reason(new RuntimeException) : self::reason($e);
            $code ??= $e === null ? 'unreachable' : self::code($e);
            $sending->forceFill(['status' => 'failed', 'error' => $reason, 'error_code' => $code])->save();
            Document::query()->whereKey($sending->document_id)->toBase()
                ->update(['email_status' => 'failed', 'email_error_code' => $code, 'updated_at' => CarbonImmutable::now()]);
            app(AuditRecorder::class)->record('document.email_failed', Document::query()->find($sending->document_id), [], ['sending' => $sending->id, 'code' => $code], self::sender($sending));
        });
    }

    /** Every trainee of the dossier who is still expected has a convocation that left. */
    private static function everyTraineeConvened(string $dossierId): bool
    {
        $dossier = Paperwork::dossier($dossierId);
        $expected = array_column(array_filter($dossier['trainees'] ?? [], fn (array $t) => $t['left_on'] === null), 'enrolment_id');
        if ($expected === []) {
            return false;
        }
        $convened = Document::query()->whereIn('enrolment_id', $expected)->where('type', DocumentType::Convocation->value)
            ->where('status', '!=', DocumentStatus::Cancelled->value)->whereNotNull('sent_at')->distinct()->count('enrolment_id');

        return $convened === count($expected);
    }

    private static function sender(DocumentSending $sending): ?User
    {
        return $sending->sent_by === null ? null : User::query()->find($sending->sent_by);
    }

    public static function reason(Throwable $e): string
    {
        if ($e instanceof DocumentMailFailed || $e instanceof PermanentDeliveryFailure) {
            return $e->getMessage();
        }
        if ($e instanceof SmtpHostRefused) {
            return 'L\'adresse du serveur d\'envoi est refusée (adresse interne).';
        }
        $code = self::smtpCode($e);

        return $code !== 0
            ? "Le serveur d'envoi a refusé le message (code {$code})."
            : 'Le serveur d\'envoi est injoignable ou a refusé le message.';
    }

    public static function code(Throwable $e): string
    {
        return match (true) {
            $e instanceof SmtpHostRefused => 'host_refused',
            $e instanceof PermanentDeliveryFailure => 'refused',
            self::smtpCode($e) !== 0 => 'smtp_'.self::smtpCode($e),
            default => 'unreachable',
        };
    }

    public static function smtpCode(Throwable $e): int
    {
        if ($e instanceof DocumentMailFailed) {
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
