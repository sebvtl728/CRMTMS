<?php

use App\Modules\Admissions\Booklet\BookletVisit;
use App\Modules\Admissions\Booklet\BookletVisits;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Europe/Paris'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('records a visit by the trainer and lists it', function () {
    $c = bookletApprentice();
    $trainer = bookletTrainerUser('Cauret');

    $this->actingAs($trainer)->post("/admissions/livret/{$c->id}/visites", ['visited_on' => '2027-02-10', 'points' => 'Points abordés : hygiène'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(BookletVisits::list($c))->toHaveCount(1)
        ->and(BookletVisits::list($c)[0])->toMatchArray(['visited_on' => '2027-02-10', 'points' => 'Points abordés : hygiène', 'trainer_user_id' => $trainer->id]);
    $log = AuditLog::query()->where('auditable_type', 'booklet_visit')->sole();
    expect($log->new_values['points'])->toBe('[donnée personnelle]');
});

it('refuses a visit in the future or without points', function () {
    $c = bookletApprentice();
    $this->actingAs(bookletTrainerUser('Cauret'))->post("/admissions/livret/{$c->id}/visites", ['visited_on' => '2027-02-16', 'points' => ''])
        ->assertSessionHasErrors(['visited_on' => 'La date ne peut pas être dans le futur.', 'points' => 'Indiquez les points abordés.']);
});

it('updates and deletes a visit softly, never one of another apprentice', function () {
    $c = bookletApprentice();
    $trainer = bookletTrainerUser('Cauret');
    $visit = BookletVisits::create($c, ['visited_on' => '2027-02-10', 'points' => 'hygiène'], $trainer);

    $this->actingAs($trainer)->put("/admissions/livret/{$c->id}/visites/{$visit->id}", ['visited_on' => '2027-02-11', 'points' => 'sécurité'])->assertSessionHasNoErrors();
    expect(BookletVisits::list($c)[0]['points'])->toBe('sécurité');

    $other = adCandidature();
    $this->actingAs(admin(twoFactor: true))->delete("/admissions/livret/{$other->id}/visites/{$visit->id}")->assertNotFound();

    $this->actingAs($trainer)->delete("/admissions/livret/{$c->id}/visites/{$visit->id}")->assertRedirect();
    expect(BookletVisits::list($c))->toBe([])
        ->and(BookletVisit::withTrashed()->count())->toBe(1);
});
