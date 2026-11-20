<?php

namespace App\Modules\Questionnaires\Campaigns;

use App\Modules\Catalogue\Trainings;
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
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Sessions\Public\Surveys;
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
 * Sends one questionnaire invitation: its e-mail template filled in, {lien}
 * the personal link (the plain token travels in the encrypted payload only,
 * never in the database). Reads the current row: a replay after success, an
 * answered or anonymised invitation does nothing. Three tries, then
 * « failed » with a fixed French reason, never the server's text.
 */
class SendSurveyInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public string $invitationId, public string $token)
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
        $invitation = SurveyInvitation::query()->with('campaign')->find($this->invitationId);
        if ($invitation === null || $invitation->email_status !== SurveyInvitation::PENDING) {
            return;
        }
        if ($invitation->email === null || $invitation->answered_at !== null) {
            $this->failed(new SurveyMailFailed('Plus aucune adresse : l\'e-mail n\'est pas parti.'));

            return;
        }
        $campaign = $invitation->campaign;
        $session = Surveys::session($campaign->training_session_id);
        $person = app(Fiches::class)->personLabels([$invitation->person_id])[$invitation->person_id] ?? null;
        $training = $session !== null ? app(Trainings::class)->find($session['training_id']) : null;
        $text = app(MailTemplates::class)->render(SurveyMailTemplates::keyFor($campaign), [
            'prenom' => (string) ($person['first_name'] ?? ''),
            'nom' => (string) ($person['last_name'] ?? ''),
            'formation' => (string) ($training->title ?? ''),
            'session' => (string) ($session['label'] ?? ''),
            'date_limite' => $campaign->closes_on->format('d/m/Y'),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'lien' => TenantUrl::route('survey.show', $this->token),
        ]);

        $error = null;
        $send = function () use ($invitation, $text, &$error): string {
            try {
                TenantMailer::for(null)->to((string) $invitation->email)->send(new SurveyMail($text['subject'], $text['body']));
            } catch (Throwable $e) {
                $error = $e;

                throw new RuntimeException(self::reason($e));
            }

            return 'Questionnaire de satisfaction envoyé par e-mail.';
        };
        $config = TenantMailer::activeConfig(null);
        if ($config !== null) {
            ConnectorRun::track($config, 'out', $send, payloadRef: 'survey:'.$invitation->id);
        } else {
            try {
                $send();
            } catch (RuntimeException) {
                // $error is set: handled below.
            }
        }

        /** @var Throwable|null $error */
        if ($error !== null) {
            $failure = new SurveyMailFailed(self::reason($error));
            if ($this->job instanceof SyncJob || $error instanceof PermanentDeliveryFailure) {
                $this->failed($failure);

                return;
            }

            throw $failure;
        }

        SurveyInvitation::query()->whereKey($invitation->id)->toBase()
            ->update(['email_status' => SurveyInvitation::SENT, 'sent_at' => CarbonImmutable::now(), 'email_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => SurveyInvitation::query()->whereKey($this->invitationId)
            ->where('email_status', SurveyInvitation::PENDING)->toBase()
            ->update(['email_status' => SurveyInvitation::FAILED, 'email_error' => self::reason($e ?? new RuntimeException)]));
    }

    /** A fixed French reason: the SMTP reply code at most, never the server's text. */
    public static function reason(Throwable $e): string
    {
        if ($e instanceof SurveyMailFailed || $e instanceof PermanentDeliveryFailure) {
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
            if (($code < 400 || $code > 599) && preg_match('/^([45]\d\d)\b/', $e->getMessage(), $m) === 1) {
                $code = (int) $m[1];
            }
        }

        return $code >= 400 && $code <= 599
            ? "Le serveur d'envoi a refusé le message (code {$code})."
            : 'Le serveur d\'envoi est injoignable ou a refusé le message.';
    }
}
