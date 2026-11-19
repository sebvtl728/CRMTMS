<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Catalogue\Training;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Qualite\Public\Measures;
use App\Modules\Sessions\Enrolments\Enrolments;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Admissions/Support/admissions.php';
require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';

beforeEach(function () {
    plSetUp();
    adSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

afterEach(fn () => Measures::forget('test.measure'));

it('shows the measures declared by the modules, and a broken one as unavailable', function () {
    Measures::register(30, 'test.measure', fn () => throw new RuntimeException('panne'));

    $this->actingAs(admin(twoFactor: true))->get('/qualite/qualiopi/30')->assertInertia(fn (Assert $p) => $p
        ->where('measures', fn ($m) => collect($m)->contains(fn ($x) => $x['value'] === 'Mesure indisponible')));
});

it('measures the complete training sheets (indicators 1 and 5)', function () {
    $full = ['objectives' => ['Maîtriser HACCP'], 'audience' => 'Cuisiniers', 'prerequisites' => 'Aucun', 'methods' => 'Ateliers', 'evaluation' => 'QCM', 'accessibility' => 'Accessible PMR'];
    Training::query()->create(['title' => 'Complète', 'das_id' => adTraining()->das_id, ...$full]);
    Training::query()->create(['title' => 'Incomplète', 'das_id' => adTraining()->das_id, 'objectives' => ['x']]);
    Training::query()->create(['title' => 'Inactive', 'das_id' => adTraining()->das_id, 'is_active' => false]);

    $measure = collect(Measures::for(5))->firstWhere('key', 'catalogue.complete_sheets');
    // adTraining() itself is active and incomplete: 1 complete of 3 active.
    expect($measure['value'])->toBe('1 / 3')->and($measure['tone'])->toBe('warn');
    expect(collect(Measures::for(1))->pluck('key'))->toContain('catalogue.complete_sheets');
});

it('measures the positioning tests received before admission (indicator 8)', function () {
    adCandidature();
    adCandidature(['person_id' => adPerson('Martin')->id, 'positioning_received_on' => null]);

    $measure = collect(Measures::for(8))->firstWhere('key', 'admissions.positioning');
    expect($measure['value'])->toBe('1 / 2')->and($measure['label'])->toContain('test de positionnement reçu');
});

it('measures the abandon rate of the last 12 months (indicator 12)', function () {
    $session = l2Session(null, ['starts_on' => '2026-06-01', 'ends_on' => '2026-06-30']);
    $dossier = l2Dossier();
    l2Enrol($dossier, $session, makePerson('Un'));
    $quit = l2Enrol($dossier, $session, makePerson('Deux'));
    app(Enrolments::class)->abandon($quit, CarbonImmutable::parse('2026-06-10'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);

    $measure = collect(Measures::for(12))->firstWhere('key', 'sessions.abandons');
    expect($measure['value'])->toBe('50 %')->and($measure['detail'])->toBe('1 abandon sur 2 inscriptions (sessions commencées depuis le 19/11/2025)');
});

it('measures the alternance contracts with a tutor (indicator 13)', function () {
    $c = adCandidature();
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());
    app(AlternanceWriter::class)->save($c->fresh(), ['contract_type' => 'apprenticeship', 'employer_organisation_id' => Organisation::query()->create(['name' => 'Hôtel'])->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30'], admin());

    $measure = collect(Measures::for(13))->firstWhere('key', 'admissions.tutors');
    expect($measure['value'])->toBe('0 / 1')->and($measure['tone'])->toBe('warn');
});
