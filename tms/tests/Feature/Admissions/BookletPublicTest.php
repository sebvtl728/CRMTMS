<?php

use App\Modules\Admissions\Booklet\BookletAnswering;
use App\Modules\Admissions\Booklet\BookletGrid;
use App\Modules\Admissions\Booklet\BookletLink;
use App\Modules\Admissions\Booklet\BookletLinks;
use App\Modules\Admissions\Booklet\BookletMail;
use App\Modules\Admissions\Booklet\BookletMonth;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Booklet\SendBookletLink;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Storage::fake('local');
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Europe/Paris'));
    Mail::fake();
    $this->c = bookletApprentice();
    Person::query()->whereKey($this->c->person_id)->update(['email' => 'lea.durand@example.test']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Sends the link of $signer for January and returns its plain token (read from the queued job). */
function bookletToken(Candidature $c, string $signer): string
{
    Queue::fake();
    app(BookletLinks::class)->send($c, '2027-01', $signer, admin());
    $token = null;
    Queue::assertPushed(SendBookletLink::class, function (SendBookletLink $job) use (&$token) {
        $token = $job->token;

        return true;
    });

    return $token;
}

/** The code e-mailed for $token (read from the faked mail). */
function bookletCode($test, string $token): string
{
    $test->post("/livret/{$token}/code")->assertOk();
    $code = null;
    Mail::assertSent(BookletMail::class, function (BookletMail $mail) use (&$code) {
        if (preg_match('/\b(\d{6})\b/', $mail->text, $m) === 1) {
            $code = $m[1];
        }

        return true;
    });

    return (string) $code;
}

it('sends the link e-mail with the personal link and marks it sent', function () {
    Queue::fake();
    $link = app(BookletLinks::class)->send($this->c, '2027-01', 'apprentice', admin());
    $job = null;
    Queue::assertPushed(SendBookletLink::class, function (SendBookletLink $j) use (&$job) {
        $job = $j;

        return true;
    });
    $job->handle();

    Mail::assertSent(BookletMail::class, fn (BookletMail $m) => $m->hasTo('lea.durand@example.test')
        && str_contains($m->text, '/livret/'.$job->token) && str_contains($m->mailSubject, 'janvier 2027'));
    expect($link->fresh()->email_status)->toBe('sent');
});

it('lets the apprentice type his weeks, then sign with the code and a drawing, which sends the tutor his link', function () {
    $token = bookletToken($this->c, 'apprentice');

    $this->get("/livret/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/booklet')->where('state', 'open')->where('signer', 'apprentice')
        ->where('month_label', 'Janvier 2027')->has('pages', 2)->where('grid', null));
    $this->postJson("/livret/{$token}/save", ['entries' => ['company' => ['w1' => 'Mise en place du buffet'], 'centre' => ['w2' => 'Cours de sauces']]])->assertOk();
    $this->postJson("/livret/{$token}/save", ['entries' => ['company' => ['w2' => 'Service']]])->assertOk();

    $code = bookletCode($this, $token);
    Queue::fake();
    $this->postJson("/livret/{$token}/sign", ['code' => $code, 'drawing' => sigDrawing()])->assertCreated();

    $january = BookletMonths::months($this->c)[1];
    expect($january['pages']['company']['entries'])->toMatchArray(['w1' => 'Mise en place du buffet', 'w2' => 'Service'])
        ->and($january['pages']['centre']['entries']['w2'])->toBe('Cours de sauces')
        ->and($january['pages']['company']['apprentice']['status'])->toBe('signed')
        ->and($january['pages']['centre']['apprentice']['status'])->toBe('signed')
        ->and($january['pages']['company']['apprentice']['name'])->toBe('Léa DURAND');
    Storage::disk('local')->assertExists(BookletMonth::query()->where('place', 'company')->value('apprentice_signature_path'));
    Queue::assertPushed(SendBookletLink::class);
    expect(BookletLink::query()->where('signer', 'tutor')->whereNull('closed_at')->value('email'))->toBe('paul.martin@example.test');
    $this->get("/livret/{$token}")->assertNotFound();
});

it('refuses a wrong, expired or exhausted code and one request a minute', function () {
    $token = bookletToken($this->c, 'apprentice');
    $code = bookletCode($this, $token);
    $this->postJson("/livret/{$token}/code")->assertStatus(429)->assertJson(['message' => BookletAnswering::WAIT]);
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $i) {
        $this->postJson("/livret/{$token}/sign", ['code' => $wrong, 'drawing' => sigDrawing()])->assertJsonValidationErrors(['code' => BookletAnswering::WRONG]);
    }
    $this->postJson("/livret/{$token}/sign", ['code' => $code, 'drawing' => sigDrawing()])->assertJsonValidationErrors(['code' => BookletAnswering::TOO_MANY]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:20', 'Europe/Paris'));
    $code = bookletCode($this, $token);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:31', 'Europe/Paris'));
    $this->postJson("/livret/{$token}/sign", ['code' => $code, 'drawing' => sigDrawing()])->assertJsonValidationErrors(['code' => BookletAnswering::EXPIRED]);
    $this->postJson("/livret/{$token}/sign", ['code' => $code, 'drawing' => sigDrawing(blank: true)])->assertJsonValidationErrors('drawing');
});

it('shows the tutor his company page and the grid only, and saves his observations and marks', function () {
    BookletMonths::page($this->c, '2027-01', 'centre')->forceFill(['apprentice_entries' => ['w1' => 'Texte du centre']])->save();
    $this->c->forceFill(['interview_report' => 'Entretien-confidentiel-7Q'])->save();
    $token = bookletToken($this->c, 'tutor');
    $key = app(BookletGrid::class)->tree($this->c)['types'][0]['competences'][0]['skills'][0]['key'];

    $response = $this->get("/livret/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('signer', 'tutor')->where('apprentice', 'Léa D.')->has('pages', 1)->where('pages.0.place', 'company')
        ->has('grid', 1)->where('grid.0.competences.0.skills.0.level', null));
    expect($response->getContent())->not->toContain('Texte du centre')->not->toContain('Entretien-confidentiel-7Q');

    $this->postJson("/livret/{$token}/save", ['observations' => ['w1' => 'Très investie'], 'marks' => [['key' => $key, 'level' => 'ECA']]])->assertOk();
    $this->postJson("/livret/{$token}/save", ['marks' => [['key' => $key, 'level' => 'Z']]])->assertJsonValidationErrors('level');

    expect(app(BookletGrid::class)->tree($this->c)['types'][0]['competences'][0]['skills'][0]['company'])->toBe('ECA')
        ->and(BookletMonths::months($this->c)[1]['pages']['company']['observations']['w1'])->toBe('Très investie');

    $code = bookletCode($this, $token);
    $this->postJson("/livret/{$token}/sign", ['code' => $code, 'drawing' => sigDrawing()])->assertCreated();
    expect(BookletMonths::months($this->c)[1]['pages']['company']['tutor']['status'])->toBe('signed')
        ->and(BookletMonths::months($this->c)[1]['pages']['centre']['tutor'])->toBeNull();
});

it('signs once when the link was closed meanwhile', function () {
    $token = bookletToken($this->c, 'apprentice');
    $link = BookletLinks::find($token);
    $code = bookletCode($this, $token);
    app(BookletAnswering::class)->sign($link, $code, sigDrawing(), '127.0.0.1', 'test');

    expect(fn () => app(BookletAnswering::class)->sign($link, $code, sigDrawing(), '127.0.0.1', 'test'))
        ->toThrow(App\Exceptions\UserFacingHttpException::class, BookletAnswering::SIGNED);
});

it('answers the same 404 for an unknown, expired, closed link or a trashed candidature', function () {
    $token = bookletToken($this->c, 'apprentice');
    $this->get('/livret/'.str_repeat('a', 40))->assertNotFound()->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    $this->postJson('/livret/'.str_repeat('a', 40).'/save', [])->assertNotFound();

    $this->c->delete();
    $this->get("/livret/{$token}")->assertNotFound()->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    $this->c->restore();
    $this->get("/livret/{$token}")->assertOk();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-18 00:01', 'Europe/Paris'));
    $this->get("/livret/{$token}")->assertNotFound();
});
