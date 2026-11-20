<?php

use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Questionnaires\Answering\SurveyAnswers;
use App\Modules\Questionnaires\Campaigns\SurveyLaunch;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/surveys.php';

beforeEach(function () {
    svSetUp();
    Queue::fake();
});

function svAnswerFull(SurveyInvitation $invitation, string $text = 'Le formateur, M. Dupont, était top'): void
{
    $keys = ['global', 'objectifs', 'animation', 'supports', 'organisation'];
    app(SurveyAnswers::class)->submit($invitation, [...array_fill_keys($keys, 4), 'recommande' => 'Oui', 'commentaires' => $text], 'email');
}

it('anonymises a person: no address, no free text, marks kept, links closed', function () {
    [$session, $people] = svSession();
    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    $lea = SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole();
    svAnswerFull($lea);
    [$plain, $hash] = SurveyInvitation::newToken();
    $tom = SurveyInvitation::query()->where('person_id', $people['Petit']->id)->sole();
    $tom->forceFill(['token_hash' => $hash])->save();

    FicheLinks::anonymise([$people['Durand']->id, $people['Petit']->id]);

    $lea->refresh();
    expect($lea->email)->toBeNull()
        ->and($lea->response->answers)->not->toHaveKey('commentaires')
        ->and($lea->response->answers['global'])->toBe(4)
        ->and($lea->response->score)->toBe(400)
        ->and($tom->fresh()->email)->toBeNull();
    $this->get("/questionnaire/{$plain}")->assertNotFound();
});

it('keeps the fiche of a person invited on an import undo', function () {
    [$session, $people] = svSession();
    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());

    expect(App\Modules\Questionnaires\Gdpr\SurveyPersonData::hasLinked('person', $people['Durand']->id))->toBeTrue()
        ->and(App\Modules\Questionnaires\Gdpr\SurveyPersonData::hasLinked('organisation', $people['Durand']->id))->toBeFalse();
});

it('repoints the invitations on a merge, keeping the answered one when both were invited', function () {
    [$session, $people] = svSession();
    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    $keep = $people['Durand'];
    $drop = $people['Petit'];
    svAnswerFull(SurveyInvitation::query()->where('person_id', $drop->id)->sole());
    [$other] = svSession('2026-08-30', ['Roux2' => null]);
    l2Enrol(App\Modules\Sessions\Dossiers\Dossier::query()->latest('created_at')->firstOrFail(), $other, $drop);
    app(SurveyLaunch::class)->launch($other->id, 'hot', admin());

    App\Modules\Questionnaires\Gdpr\SurveyPersonData::merge('person', $keep->id, $drop->id);

    $invitations = SurveyInvitation::query()->where('person_id', $keep->id)->get();
    expect($invitations)->toHaveCount(2)
        ->and($invitations->whereNotNull('answered_at'))->toHaveCount(1)
        ->and(SurveyInvitation::query()->where('person_id', $drop->id)->count())->toBe(0);
});

it('exports the questionnaires of a person', function () {
    [$session, $people] = svSession();
    app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    svAnswerFull(SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole(), 'Bien');

    $export = FicheLinks::export($people['Durand']->id);

    expect($export['questionnaires'][0])->toMatchArray(['questionnaire' => 'Questionnaire de satisfaction de fin de formation', 'repondu_le' => '2026-09-01'])
        ->and($export['questionnaires'][0]['reponses']['Vos commentaires et suggestions'])->toBe('Bien');
});
