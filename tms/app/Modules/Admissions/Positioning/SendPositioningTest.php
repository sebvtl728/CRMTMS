<?php

namespace App\Modules\Admissions\Positioning;

use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one positioning e-mail: the message with {lien} filled in (the
 * plain token travels in the encrypted payload only, never in the
 * database) and the PDF of each test. Reads the current row: a replay after
 * success does nothing. Three tries, then « failed » with a fixed French
 * reason, never the server's text.
 */
class SendPositioningTest implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const MISSING = 'Le fichier d\'un test est introuvable : l\'e-mail n\'est pas parti.';

    public const ANONYMISED = 'Le candidat a été anonymisé : l\'e-mail n\'est pas parti.';

    public int $tries = 3;

    public function __construct(public string $sendingId, public string $token)
    {
        $this->captureTenant();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $sending = PositioningSending::query()->find($this->sendingId);
        if ($sending === null || $sending->email_status !== PositioningSending::PENDING) {
            return;
        }
        if ($sending->email === null || $sending->subject === null || $sending->message === null) {
            $this->failed(new PositioningMailFailed(self::ANONYMISED));

            return;
        }
        // An online test alone sends no file.
        $files = PositioningAttachments::read($sending->versionIds());
        if ($files === null || ($files === [] && $sending->online_test_version_id === null)) {
            $this->failed(new PositioningMailFailed(self::MISSING));

            return;
        }

        $text = str_replace(PositioningSendings::LINK, TenantUrl::route('positioning.show', $this->token), $sending->message);
        $error = null;
        $send = function () use ($sending, $text, $files, &$error): string {
            try {
                TenantMailer::for(null)->to((string) $sending->email)->send(new PositioningMail((string) $sending->subject, $text, $files));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return 'Test de positionnement envoyé par e-mail.';
        };

        $config = TenantMailer::activeConfig(null);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'positioning:'.$sending->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error */
        if ($error !== null) {
            $failure = new PositioningMailFailed(self::reason($error));
            if ($this->job instanceof SyncJob || $error instanceof PermanentDeliveryFailure) {
                $this->failed($failure);

                return;
            }

            throw $failure;
        }

        PositioningSending::query()->whereKey($sending->id)->toBase()
            ->update(['email_status' => PositioningSending::SENT, 'sent_at' => CarbonImmutable::now(), 'email_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => PositioningSending::query()->whereKey($this->sendingId)->where('email_status', PositioningSending::PENDING)->toBase()
            ->update(['email_status' => PositioningSending::FAILED, 'email_error' => self::reason($e ?? new RuntimeException)]));
    }

    /** A fixed French reason: the SMTP reply code at most, never the server's text. */
    public static function reason(Throwable $e): string
    {
        if ($e instanceof PositioningMailFailed || $e instanceof PermanentDeliveryFailure) {
            return $e->getMessage();
        }
        if ($e instanceof SmtpHostRefused) {
            return 'L\'adresse du serveur d\'envoi est refusée (adresse interne).';
        }
        $code = 0;
        if ($e instanceof TransportExceptionInterface || $e instanceof RuntimeException) {
            $code = (int) $e->getCode();
            if (($code < 400 || $code > 599) && preg_match('/got code "([45]\d\d)"/', $e->getMessage(), $m) === 1) {
                $code = (int) $m[1];
            }
        }

        return $code >= 400 && $code <= 599
            ? "Le serveur d'envoi a refusé le message (code {$code})."
            : 'Le serveur d\'envoi est injoignable ou a refusé le message.';
    }
}
