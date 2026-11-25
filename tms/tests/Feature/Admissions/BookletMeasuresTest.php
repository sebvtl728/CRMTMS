<?php

use App\Modules\Admissions\Booklet\BookletGrid;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Booklet\BookletVisits;
use App\Modules\Admissions\Quality\BookletMeasures;
use App\Modules\Catalogue\Competences;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Todo\TodoSources;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    Http::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-15 10:00', 'Europe/Paris'));
    $this->c = bookletApprentice(); // contract 04/01/2027 – 31/03/2027
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('measures the skills evaluated on the centre side (indicator 11)', function () {
    Training::query()->whereKey($this->c->training_id)->update(['competences' => Competences::parse("AT Préparer\nCP Mise en place\n- Peser les produits\n- Tailler les légumes\n")]);
    $grid = app(BookletGrid::class);
    $grid->set($this->c, $grid->tree($this->c)['types'][0]['competences'][0]['skills'][0]['key'], 'centre', 'A', admin());

    expect(BookletMeasures::skills())->toMatchArray(['value' => '1 / 2', 'tone' => 'warn']);
});

it('measures the company pages signed by the tutor and the visits (indicator 28)', function () {
    BookletMonths::page($this->c, '2027-01', 'company')->forceFill(['tutor_signed_at' => now()])->save();
    BookletMonths::markPaper($this->c, '2027-02', 'tutor', admin());
    BookletVisits::create($this->c, ['visited_on' => '2027-02-10', 'points' => 'hygiène'], admin());

    expect(BookletMeasures::tutorPages())->toMatchArray(['value' => '2 / 2', 'tone' => 'ok'])
        ->and(BookletMeasures::visits()['value'])->toBe('1 visite pour 1 apprenti');
});

it('lists the booklets with pages not visaed for over 30 days', function () {
    $trainer = bookletTrainerUser('Cauret');
    $count = fn ($user) => collect(TodoSources::for($user))->firstWhere('key', 'admissions.booklet_visa')['count'] ?? 0;
    expect($count($trainer))->toBe(1); // January ended 43 days ago, February 15 days ago

    BookletMonths::visa(BookletMonths::page($this->c, '2027-01', 'company'), $trainer);
    BookletMonths::visa(BookletMonths::page($this->c, '2027-01', 'centre'), $trainer);
    expect($count($trainer))->toBe(0)
        ->and($count(bookletTrainerUser('Autre')))->toBe(0);
});
