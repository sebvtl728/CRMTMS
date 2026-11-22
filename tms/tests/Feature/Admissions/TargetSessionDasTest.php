<?php

use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Sessions\Public\Admission;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

function adDasSession(string $trainingId): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => $trainingId, 'kind' => 'inter', 'organisation_id' => null,
        'starts_on' => '2027-01-05', 'ends_on' => '2027-06-30', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
}

it('refuses a targeted session of another DAS than the candidature', function () {
    $training = adTraining();
    $session = adDasSession($training->id);
    // The session was filed under another DAS (its training changed DAS since).
    TrainingSession::query()->whereKey($session->id)->update(['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    $c = adCandidature();

    try {
        app(CandidatureWriter::class)->update($c, ['target_session_id' => $session->id], admin());
        test()->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['target_session_id'][0])->toBe('Choisissez une session du DAS de la candidature.');
    }
});

it('accepts a targeted session of the candidature\'s DAS', function () {
    $training = adTraining();
    $session = adDasSession($training->id);
    $c = adCandidature();

    app(CandidatureWriter::class)->update($c, ['target_session_id' => $session->id], admin());
    expect($c->refresh()->target_session_id)->toBe($session->id);
});

it('offers only the sessions of the DAS, and the trainings with their DAS', function () {
    $training = adTraining();
    $kept = adDasSession($training->id);
    $other = adDasSession($training->id);
    TrainingSession::query()->whereKey($other->id)->update(['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);

    expect(array_column(Admission::sessionOptions($training->id, $training->das_id), 'id'))->toBe([$kept->id]);

    $c = adCandidature();
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('options.training_options.0.das_id', $training->das_id)
        ->where('options.sessions', fn ($s) => collect($s)->pluck('id')->all() === [$kept->id]));
});
