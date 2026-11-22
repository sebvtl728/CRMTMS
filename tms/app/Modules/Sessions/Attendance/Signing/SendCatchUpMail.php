<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Planning\PlanningRows;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use RuntimeException;
use Throwable;

/**
 * Sends the personal link of a remote catch-up (template attendance_catch_up;
 * the plain token travels in the encrypted payload only). Reads the current
 * row: a revoked, used or anonymised link sends nothing. Three tries, then
 * « failed » with a fixed French reason, never the server's text.
 */
class SendCatchUpMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public string $linkId, public string $token)
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
        $link = CatchUpLink::query()->find($this->linkId);
        if ($link === null || $link->email_status !== 'pending' || $link->used_at !== null || $link->revoked_at !== null) {
            return;
        }
        if ($link->email === null) {
            $this->failed(new RuntimeException('no address'));

            return;
        }
        $slot = SignSlot::query()->findOrFail($link->slot_id);
        $session = TrainingSession::withTrashed()->findOrFail($slot->training_session_id);
        $personId = (string) Enrolment::query()->whereKey($link->enrolment_id)->value('person_id');
        $person = app(Fiches::class)->personLabels([$personId])[$personId] ?? null;
        $text = app(MailTemplates::class)->render(CatchUpMailTemplates::LINK, [
            'stagiaire' => (string) ($person['label'] ?? ''),
            'date' => $slot->date->locale('fr')->isoFormat('dddd D MMMM YYYY'),
            'demi_journee' => mb_strtolower(SignSlot::label($slot->half)),
            'session' => PlanningRows::sessionLabels([$session])[$session->id] ?? $session->number,
            'echeance' => $link->expires_at->timezone('Europe/Paris')->format('d/m/Y'),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'lien' => TenantUrl::route('signing.public.remote.show', $this->token),
        ]);

        $error = null;
        $send = function () use ($link, $text, &$error): string {
            try {
                TenantMailer::for(null)->to((string) $link->email)->send(new CatchUpMail($text['subject'], $text['body']));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return 'Lien de rattrapage d\'émargement envoyé par e-mail.';
        };
        $config = TenantMailer::activeConfig(null);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'catch_up:'.$link->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error */
        if ($error !== null) {
            if ($this->job instanceof SyncJob || $error instanceof PermanentDeliveryFailure) {
                $this->failed($error);

                return;
            }

            throw new RuntimeException(self::reason($error));
        }
        CatchUpLink::query()->whereKey($link->id)->toBase()->update(['email_status' => 'sent', 'email_error_code' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => CatchUpLink::query()->whereKey($this->linkId)->where('email_status', 'pending')->toBase()
            ->update(['email_status' => 'failed', 'email_error_code' => $e instanceof SmtpHostRefused ? 'host_refused' : 'refused']));
    }

    /** A fixed French reason, never the server's text. */
    public static function reason(Throwable $e): string
    {
        return $e instanceof SmtpHostRefused
            ? 'L\'adresse du serveur d\'envoi est refusée (adresse interne).'
            : 'Le serveur d\'envoi est injoignable ou a refusé le message.';
    }
}
