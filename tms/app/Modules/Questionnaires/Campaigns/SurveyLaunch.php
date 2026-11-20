<?php

namespace App\Modules\Questionnaires\Campaigns;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Templates\DefaultTemplates;
use App\Modules\Questionnaires\Templates\SurveyTemplates;
use App\Modules\Sessions\Public\Surveys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Launches the questionnaire of a session (hot or cold, trainees) from the
 * active default template, its questions frozen, open LINK_DAYS days; the
 * hot one gets the session QR link and brings the entreprise questionnaire
 * along when that template is active. Launching again invites the
 * recipients added since. By a user ($by), an impossible launch is a French
 * error; automatic (null), it is skipped (null).
 */
final class SurveyLaunch
{
    public const LINK_DAYS = 30;

    /** @var array<string, string> */
    public const TITLES = [
        'hot.trainee' => 'Questionnaire de satisfaction de fin de formation',
        'cold.trainee' => 'Questionnaire de suivi après la formation',
        'hot.client' => 'Questionnaire de satisfaction entreprise',
    ];

    /** Invitations created by the last launch(). */
    public int $invited = 0;

    /** E-mails queued by the last launch(). */
    public int $mailed = 0;

    public function __construct(private readonly Fiches $fiches) {}

    public function launch(string $sessionId, string $kind, ?User $by): ?SurveyCampaign
    {
        $this->invited = $this->mailed = 0;
        DefaultTemplates::ensure();
        $session = Surveys::session($sessionId);
        if ($session === null) {
            return $by === null ? null : throw new UserFacingHttpException(404, 'Session introuvable.');
        }
        if ($session['cancelled']) {
            return $by === null ? null : throw new UserFacingHttpException(409, 'La session est annulée.');
        }

        return DB::transaction(function () use ($sessionId, $kind, $by): ?SurveyCampaign {
            // One launch of a session at a time (two clicks, the daily command).
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['survey:'.$sessionId]);
            $campaign = $this->open($sessionId, $kind, SurveyCampaign::TRAINEE, $by);
            if ($campaign === null) {
                return $by === null ? null : throw new UserFacingHttpException(409,
                    'Aucun modèle de questionnaire '.($kind === SurveyCampaign::HOT ? 'à chaud' : 'à froid').' actif par défaut : choisissez-en un dans Administration › Questionnaires.');
            }
            $this->inviteMissing($campaign);
            if ($kind === SurveyCampaign::HOT) {
                $client = $this->open($sessionId, $kind, SurveyCampaign::CLIENT, $by);
                if ($client !== null) {
                    $this->inviteMissing($client);
                }
            }

            return $campaign;
        });
    }

    /**
     * Creates the missing invitations of a campaign; queues the e-mail of
     * those having an address. Returns the invitations created.
     */
    public function inviteMissing(SurveyCampaign $campaign): int
    {
        $recipients = $campaign->audience === SurveyCampaign::TRAINEE
            ? array_map(fn (array $t) => ['person_id' => $t['person_id'], 'enrolment_id' => $t['enrolment_id']], Surveys::trainees($campaign->training_session_id))
            : array_map(fn (string $id) => ['person_id' => $id, 'enrolment_id' => null], Surveys::clientContacts($campaign->training_session_id));
        $known = $campaign->invitations()->pluck('person_id')->all();
        $recipients = array_values(array_filter($recipients, fn (array $r) => ! in_array($r['person_id'], $known, true)));
        $emails = $this->fiches->personEmails(array_column($recipients, 'person_id'));
        $created = 0;
        foreach ($recipients as $recipient) {
            $known[] = $recipient['person_id'];
            $email = $emails[$recipient['person_id']] ?? null;
            [$token, $hash] = SurveyInvitation::newToken();
            $invitation = $campaign->invitations()->create([
                ...$recipient,
                'email' => $email === null ? null : mb_strtolower(trim($email)),
                'token_hash' => $hash,
                'email_status' => $email === null ? SurveyInvitation::NO_EMAIL : SurveyInvitation::PENDING,
            ]);
            $created++;
            if ($email !== null) {
                SendSurveyInvitation::dispatch($invitation->id, $token)->afterCommit();
                $this->mailed++;
            }
        }
        $this->invited += $created;

        return $created;
    }

    /** The campaign of a session, type and public; created from the default template; null without one. */
    private function open(string $sessionId, string $kind, string $audience, ?User $by): ?SurveyCampaign
    {
        $campaign = SurveyCampaign::query()->where('training_session_id', $sessionId)->where('kind', $kind)->where('audience', $audience)->first();
        if ($campaign !== null) {
            return $campaign;
        }
        $template = SurveyTemplates::defaultFor($kind, $audience);
        if ($template === null) {
            return null;
        }
        $qr = null;
        if ($kind === SurveyCampaign::HOT && $audience === SurveyCampaign::TRAINEE) {
            $qr = bin2hex(random_bytes(32));
        }
        $today = CarbonImmutable::now('Europe/Paris');

        return SurveyCampaign::query()->create([
            'training_session_id' => $sessionId,
            'template_id' => $template->id,
            'kind' => $kind,
            'audience' => $audience,
            'title' => self::TITLES["{$kind}.{$audience}"] ?? $template->name,
            'questions' => $template->questions,
            'qr_token_hash' => $qr === null ? null : hash('sha256', $qr),
            'qr_token_encrypted' => $qr === null ? null : Crypt::encryptString($qr),
            'opened_at' => $today,
            'closes_on' => $today->addDays(self::LINK_DAYS)->toDateString(),
            'launched_by' => $by?->id,
        ]);
    }
}
