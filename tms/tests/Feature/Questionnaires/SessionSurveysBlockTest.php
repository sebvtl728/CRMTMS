<?php

use App\Modules\Questionnaires\Answering\SurveyAnswers;
use App\Modules\Questionnaires\Campaigns\SurveyLaunch;
use App\Modules\Questionnaires\Results\SurveyResults;
use App\Modules\Questionnaires\Settings\ColdDelay;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Public\SessionBlocks;
use App\Modules\Sessions\Public\SessionCards;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/surveys.php';

beforeEach(function () {
    svSetUp();
    Queue::fake();
});

/** Answers of the default hot template: the five marks, the choice, the text. */
function svAnswer(SurveyInvitation $invitation, array $marks, string $choice = 'Oui', ?string $text = null): void
{
    $keys = ['global', 'objectifs', 'animation', 'supports', 'organisation'];
    app(SurveyAnswers::class)->submit($invitation, [...array_combine($keys, $marks), 'recommande' => $choice, 'commentaires' => $text], 'email');
}

it('computes the results of a campaign: response and satisfaction rates, per question, texts without names', function () {
    [$session, $people] = svSession('2026-08-31', ['Durand' => 'a@example.test', 'Petit' => 'b@example.test', 'Roux' => null, 'Blanc' => null]);
    $campaign = app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    $inv = fn (string $name) => SurveyInvitation::query()->where('person_id', $people[$name]->id)->sole();
    svAnswer($inv('Durand'), [5, 5, 5, 5, 5], 'Oui', 'Parfait');
    svAnswer($inv('Petit'), [3, 3, 4, 3, 3], 'Non');

    $r = SurveyResults::campaign($campaign);

    expect($r)->toMatchArray(['invited' => 4, 'answered' => 2, 'response_rate' => 50, 'average' => 410, 'average_label' => '4,1/5', 'satisfaction_rate' => 50])
        ->and($r['questions'][0])->toMatchArray(['id' => 'global', 'average' => 400, 'distribution' => [0, 0, 1, 0, 1]])
        ->and($r['questions'][5]['counts'])->toBe(['Oui' => 1, 'Non' => 1])
        ->and($r['questions'][6]['texts'])->toBe(['Parfait'])
        ->and(SurveyResults::rate(0, 0))->toBeNull()
        ->and(SurveyResults::rate(2, 3))->toBe(67);
});

it('shows the block to who sees the session, the launch to who may update it', function () {
    [$session] = svSession();
    $viewer = l2User([SessionsPermissions::SESSIONS_VIEW => null]);
    $manager = l2User([SessionsPermissions::SESSIONS_VIEW => null, SessionsPermissions::SESSIONS_UPDATE => null]);
    $block = fn ($user) => collect(SessionBlocks::for($session->id, $user))->firstWhere('key', 'questionnaires.session');

    expect($block(userWithPermissions(['crm.fiches.view'])))->toBeNull()
        ->and($block($viewer)['props']['can_launch'])->toBeFalse()
        ->and($block($manager)['props']['can_launch'])->toBeTrue()
        ->and($block($manager)['props']['campaigns'])->toBe([]);

    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    $props = $block($manager)['props'];
    expect($props['campaigns'])->toHaveCount(1)
        ->and($props['campaigns'][0]['invitations'])->toHaveCount(3)
        ->and($props['campaigns'][0]['invitations'][0])->toHaveKeys(['name', 'email_status', 'answered'])
        ->and($props['qr_url'])->toBe("/sessions/{$session->id}/questionnaire-qr");
});

it('gives the satisfaction card: n/a before the end, to do after without answers, done with answers', function () {
    $viewer = admin();
    [$future] = svSession('2026-09-10');
    [$ended, $people] = svSession('2026-08-31');
    $card = fn (string $id) => collect(SessionCards::for([$id], $viewer)[$id] ?? [])->firstWhere('key', 'questionnaires.satisfaction');

    $empty = plSession('2026-08-01', '2026-08-05');
    expect($card($empty->id))->toBeNull()
        ->and($card($future->id)['state'])->toBe('na')
        ->and($card($ended->id)['state'])->toBe('todo');
    app(SurveyLaunch::class)->launch($ended->id, 'hot', admin());
    expect($card($ended->id)['state'])->toBe('todo')->and($card($ended->id)['value'])->toBe('0 / 3 réponses');
    svAnswer(SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole(), [5, 4, 4, 5, 4]);
    expect($card($ended->id))->toMatchArray(['state' => 'done', 'value' => '4,4/5 · 1 / 3 réponses', 'anchor' => 'questionnaires']);
});

it('shows the QR code page of the hot questionnaire to who sees the session', function () {
    [$session] = svSession();
    $this->actingAs(l2User([SessionsPermissions::SESSIONS_VIEW => null]));

    $this->get("/sessions/{$session->id}/questionnaire-qr")->assertNotFound();
    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    $this->get("/sessions/{$session->id}/questionnaire-qr")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('sessions/questionnaire-qr')
        ->where('url', fn (string $url) => preg_match('#/questionnaire/s/[0-9a-f]{64}$#', $url) === 1)
        ->where('qr', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml;base64,')));

    $this->actingAs(userWithPermissions(['crm.fiches.view']));
    $this->get("/sessions/{$session->id}/questionnaire-qr")->assertNotFound();
});

it('says when each questionnaire leaves by itself (no click needed)', function () {
    [$session] = svSession('2026-08-31');
    $props = collect(SessionBlocks::for($session->id, admin()))->firstWhere('key', 'questionnaires.session')['props'];

    // Hot: the last day (13:30); cold: 3 months after it (default).
    expect($props['hot_on'])->toBe('31/08/2026')
        ->and($props['cold_on'])->toBe('30/11/2026');

    ColdDelay::set(0);
    expect(collect(SessionBlocks::for($session->id, admin()))->firstWhere('key', 'questionnaires.session')['props']['cold_on'])->toBeNull();
});
