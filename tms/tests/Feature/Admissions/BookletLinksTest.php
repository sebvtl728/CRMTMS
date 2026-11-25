<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Booklet\BookletLink;
use App\Modules\Admissions\Booklet\BookletLinks;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Booklet\SendBookletLink;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Europe/Paris'));
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bookletWithEmail(): Candidature
{
    $c = bookletApprentice();
    Person::query()->whereKey($c->person_id)->update(['email' => 'lea.durand@example.test']);

    return $c;
}

it('sends the monthly link of the apprentice: hashed token, 30 days of Paris, plain token only in the job', function () {
    $c = bookletWithEmail();

    $this->actingAs(bookletTrainerUser('Cauret'))->post("/admissions/livret/{$c->id}/mois/2027-01/envoyer/apprentice")
        ->assertRedirect()->assertSessionHas('success', 'Lien envoyé à lea.durand@example.test.');

    $link = BookletLink::query()->sole();
    expect($link->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($link->email)->toBe('lea.durand@example.test')
        ->and($link->signer_name)->toBe('Léa DURAND')
        ->and($link->expires_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2027-03-17 23:59')
        ->and(BookletMonths::months($c)[1]['pages']['company']['apprentice']['status'])->toBe('sent');
    Queue::assertPushed(SendBookletLink::class, fn (SendBookletLink $job) => $job->linkId === $link->id
        && hash('sha256', $job->token) === $link->token_hash);
});

it('closes the open link when sending again', function () {
    $c = bookletWithEmail();
    $first = app(BookletLinks::class)->send($c, '2027-01', 'apprentice', admin());
    $second = app(BookletLinks::class)->send($c, '2027-01', 'apprentice', admin());

    expect($first->fresh()->closed_at)->not->toBeNull()->and($second->fresh()->closed_at)->toBeNull();
});

it('refuses without e-mail, without tutor, for a month to come or a part already signed', function () {
    $c = bookletApprentice();
    $trainer = bookletTrainerUser('Cauret');
    $base = "/admissions/livret/{$c->id}/mois";
    $this->actingAs($trainer)->post("{$base}/2027-01/envoyer/apprentice")->assertStatus(409);
    Person::query()->whereKey($c->person_id)->update(['email' => 'lea.durand@example.test']);
    $this->actingAs($trainer)->post("{$base}/2027-03/envoyer/apprentice")->assertStatus(409);
    BookletMonths::markPaper($c, '2027-01', 'apprentice', $trainer);
    $this->actingAs($trainer)->post("{$base}/2027-01/envoyer/apprentice")->assertStatus(409);

    AlternanceContract::query()->where('candidature_id', $c->id)->update(['tutor_person_id' => null]);
    expect(fn () => app(BookletLinks::class)->send($c, '2027-02', 'tutor', $trainer))->toThrow(UserFacingHttpException::class, BookletLinks::NO_TUTOR);
    Queue::assertNothingPushed();
});

it('keeps the open link of the former tutor and sends the next one to the new tutor', function () {
    $c = bookletApprentice();
    $old = app(BookletLinks::class)->send($c, '2027-01', 'tutor', admin());
    $new = adPerson('Bernard', ['first_name' => 'Anne', 'birth_date' => null, 'email' => 'anne.bernard@example.test']);
    AlternanceContract::query()->where('candidature_id', $c->id)->update(['tutor_person_id' => $new->id]);

    expect(BookletLinks::find('x'))->toBeNull()
        ->and($old->fresh()->closed_at)->toBeNull()
        ->and($old->email)->toBe('paul.martin@example.test');
    $next = app(BookletLinks::class)->send($c, '2027-02', 'tutor', admin());
    expect($next->email)->toBe('anne.bernard@example.test')->and($next->signer_name)->toBe('Anne BERNARD');
});
