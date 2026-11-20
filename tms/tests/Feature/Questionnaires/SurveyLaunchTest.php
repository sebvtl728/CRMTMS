<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Questionnaires\Campaigns\SendSurveyInvitation;
use App\Modules\Questionnaires\Campaigns\SurveyLaunch;
use App\Modules\Questionnaires\Campaigns\SurveyMail;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use App\Modules\Questionnaires\Templates\DefaultTemplates;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/Support/surveys.php';

beforeEach(fn () => svSetUp());

it('launches the hot questionnaire: one invitation per trainee, an e-mail for those having an address', function () {
    Queue::fake();
    [$session] = svSession();

    $campaign = app(SurveyLaunch::class)->launch($session->id, 'hot', admin());

    expect($campaign->kind)->toBe('hot')->and($campaign->audience)->toBe('trainee')
        ->and($campaign->closes_on->toDateString())->toBe('2026-10-01')
        ->and($campaign->qr_token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and(Crypt::decryptString($campaign->qr_token_encrypted))->toMatch('/^[0-9a-f]{64}$/')
        ->and(collect($campaign->questions)->pluck('type')->first())->toBe('rating')
        ->and($campaign->invitations()->count())->toBe(3)
        ->and($campaign->invitations()->where('email_status', 'pending')->count())->toBe(2)
        ->and($campaign->invitations()->where('email_status', 'none')->count())->toBe(1)
        ->and(SurveyCampaign::query()->where('audience', 'client')->count())->toBe(0);
    Queue::assertPushed(SendSurveyInvitation::class, 2);
});

it('invites only the trainees added since, on a second launch', function () {
    Queue::fake();
    [$session] = svSession();
    $launch = app(SurveyLaunch::class);
    $launch->launch($session->id, 'hot', admin());
    l2Enrol(App\Modules\Sessions\Dossiers\Dossier::query()->firstOrFail(), $session, makePerson('Blanc', ['email' => 'zoe.blanc@example.test']));

    $campaign = $launch->launch($session->id, 'hot', admin());

    expect(SurveyCampaign::query()->count())->toBe(1)->and($campaign->invitations()->count())->toBe(4);
    Queue::assertPushed(SendSurveyInvitation::class, 3);
});

it('launches the entreprise questionnaire with the hot one when its template is active', function () {
    Queue::fake();
    DefaultTemplates::ensure();
    SurveyTemplate::query()->where('audience', 'client')->update(['active' => true]);
    [$session] = svSession();

    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());

    $client = SurveyCampaign::query()->where('audience', 'client')->sole();
    expect($client->qr_token_hash)->toBeNull()
        ->and($client->invitations()->pluck('email')->all())->toBe(['paul.martin@hotel-parc.test']);
});

it('launches with no e-mail at all when no trainee has an address (the QR still works)', function () {
    Queue::fake();
    [$session] = svSession('2026-08-31', ['Roux' => null, 'Blanc' => null]);

    $campaign = app(SurveyLaunch::class)->launch($session->id, 'hot', admin());

    expect($campaign->invitations()->where('email_status', 'none')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('refuses a cancelled session, and launches nothing without an active default template', function () {
    [$session] = svSession();
    $session->forceFill(['status' => 'cancelled', 'cancel_reason_id' => crmItem(ReferenceList::CancelReason, 'Autre')->id, 'cancelled_on' => '2026-08-01'])->save();

    expect(fn () => app(SurveyLaunch::class)->launch($session->id, 'hot', admin()))->toThrow(UserFacingHttpException::class, 'La session est annulée.')
        ->and(app(SurveyLaunch::class)->launch($session->id, 'hot', null))->toBeNull();

    [$other] = svSession();
    SurveyTemplate::query()->where('kind', 'cold')->update(['active' => false]);
    expect(app(SurveyLaunch::class)->launch($other->id, 'cold', null))->toBeNull()
        ->and(fn () => app(SurveyLaunch::class)->launch($other->id, 'cold', admin()))->toThrow(UserFacingHttpException::class);
});

it('launches from the session page with the right to update the session only', function () {
    Queue::fake();
    [$session] = svSession();

    $this->actingAs(l2User([SessionsPermissions::SESSIONS_VIEW => null]));
    $this->post("/sessions/{$session->id}/questionnaires/hot")->assertForbidden();
    $this->post("/sessions/{$session->id}/questionnaires/autre")->assertNotFound();

    $this->actingAs(l2User([SessionsPermissions::SESSIONS_VIEW => null, SessionsPermissions::SESSIONS_UPDATE => null]));
    $this->post("/sessions/{$session->id}/questionnaires/hot")->assertRedirect()->assertSessionHas('status', 'Questionnaire à chaud lancé : 2 e-mails en cours d\'envoi.');
});

it('mails the personal link, then marks the invitation sent', function () {
    Mail::fake();
    [$session] = svSession('2026-08-31', ['Durand' => 'lea.durand@example.test']);

    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());

    expect(SurveyInvitation::query()->sole()->email_status)->toBe('sent');
    Mail::assertSent(SurveyMail::class, function (SurveyMail $mail) {
        $mail->build();

        return $mail->hasTo('lea.durand@example.test')
            && preg_match('#/questionnaire/[0-9a-f]{64}#', $mail->text) === 1
            && str_contains($mail->text, 'Léa')
            && ! str_contains($mail->text, '{lien}');
    });
});

it('records a failure without the server text', function () {
    docSmtp(fn () => throw new TransportException('550 5.1.1 <lea.durand@example.test>: rejected for user cap-user'));
    [$session] = svSession('2026-08-31', ['Durand' => 'lea.durand@example.test']);

    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());

    $invitation = SurveyInvitation::query()->sole();
    expect($invitation->email_status)->toBe('failed')
        ->and($invitation->email_error)->toBe("Le serveur d'envoi a refusé le message (code 550).");
});
