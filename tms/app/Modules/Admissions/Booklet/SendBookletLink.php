<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Trainings;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Crm\Public\Fiches;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use RuntimeException;
use Throwable;

/**
 * Sends the monthly link of the booklet to the apprentice or the tutor
 * (templates booklet_apprentice / booklet_tutor; the plain token travels in
 * the encrypted payload only). Reads the current row: a closed or expired
 * link sends nothing. Three tries, then « failed », never the server's text.
 */
class SendBookletLink implements ShouldBeEncrypted, ShouldQueue
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
        $link = BookletLink::query()->find($this->linkId);
        if ($link === null || $link->email_status !== 'pending' || ! BookletMonths::isOpen($link) || $link->email === null) {
            return;
        }
        $candidature = Candidature::query()->findOrFail($link->candidature_id);
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->firstOrFail();
        $fiches = app(Fiches::class);
        $recipient = $fiches->contactCard('person', $link->person_id);
        $apprentice = $fiches->contactCard('person', $candidature->person_id);
        $employer = $fiches->contactCard('organisation', $contract->employer_organisation_id);
        $text = app(MailTemplates::class)->render($link->signer === BookletLink::TUTOR ? BookletMail::TUTOR : BookletMail::APPRENTICE, [
            'prenom' => (string) ($recipient['first_name'] ?? ''),
            'nom' => (string) ($recipient['last_name'] ?? ''),
            'mois' => mb_strtolower(BookletMonths::label($link->month)),
            'apprenti' => (string) ($apprentice['label'] ?? ''),
            'entreprise' => (string) ($employer['label'] ?? ''),
            'formation' => $candidature->training_id === null ? '' : (string) app(Trainings::class)->find($candidature->training_id)?->title,
            'lien' => TenantUrl::route('booklet.public.show', $this->token),
            'date_limite' => $link->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
        ]);

        $error = null;
        $send = function () use ($link, $text, &$error): string {
            try {
                TenantMailer::for(null)->to((string) $link->email)->send(new BookletMail($text['subject'], $text['body']));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::REASON);
            }

            return 'Lien du livret d\'apprentissage envoyé par e-mail.';
        };
        $config = TenantMailer::activeConfig(null);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'booklet:'.$link->id);
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

            throw new RuntimeException(self::REASON);
        }
        BookletLink::query()->whereKey($link->id)->toBase()->update(['email_status' => 'sent', 'sent_at' => now()]);
    }

    public const REASON = 'Le serveur d\'envoi est injoignable ou a refusé le message.';

    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => BookletLink::query()->whereKey($this->linkId)->where('email_status', 'pending')->toBase()
            ->update(['email_status' => 'failed']));
    }
}
