<?php

use App\Modules\Admissions\Booklet\BookletAutomation;
use App\Modules\Admissions\Booklet\BookletLink;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Booklet\SendBookletLink;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    Illuminate\Support\Facades\Http::fake(); // the alternance convention issued with the dossier
    $this->c = bookletApprentice(); // contract 04/01/2027 – 31/03/2027
    Person::query()->whereKey($this->c->person_id)->update(['email' => 'lea.durand@example.test']);
    AutomationSettings::save(['booklet' => true]);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bookletRun(string $day): int
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($day.' 07:15', 'Europe/Paris'));

    return app(BookletAutomation::class)->run(CarbonImmutable::now('Europe/Paris')->startOfDay());
}

it('sends the link of the past month to the apprentice on the 1st, once', function () {
    expect(bookletRun('2027-02-01'))->toBe(1)
        ->and(BookletLink::query()->sole()->month->format('Y-m'))->toBe('2027-01')
        ->and(BookletLink::query()->sole()->signer)->toBe('apprentice')
        ->and(BookletLink::query()->sole()->created_by)->toBeNull();
    Queue::assertPushed(SendBookletLink::class, 1);

    expect(bookletRun('2027-02-02'))->toBe(0)->and(BookletLink::query()->count())->toBe(1);
});

it('catches up later in the month, and never for a part signed on paper', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-01 10:00', 'Europe/Paris'));
    BookletMonths::markPaper($this->c, '2027-01', 'apprentice', admin());
    expect(bookletRun('2027-02-03'))->toBe(0);
    expect(bookletRun('2027-03-05'))->toBe(1)
        ->and(BookletLink::query()->sole()->month->format('Y-m'))->toBe('2027-02');
});

it('does nothing when switched off, outside the contract, or without e-mail (tried again later)', function () {
    AutomationSettings::save(['booklet' => false]);
    expect(bookletRun('2027-02-01'))->toBe(0);
    AutomationSettings::save(['booklet' => true]);
    expect(bookletRun('2026-12-01'))->toBe(0);

    Person::query()->whereKey($this->c->person_id)->update(['email' => null]);
    expect(bookletRun('2027-02-01'))->toBe(0);
    Person::query()->whereKey($this->c->person_id)->update(['email' => 'lea.durand@example.test']);
    expect(bookletRun('2027-02-02'))->toBe(1);
});

it('reminds the signer at J+7 and J+14 with a new link, once each', function () {
    bookletRun('2027-02-01');
    $first = BookletLink::query()->sole();

    expect(bookletRun('2027-02-07'))->toBe(0)
        ->and(bookletRun('2027-02-08'))->toBe(1)
        ->and($first->fresh()->closed_at)->not->toBeNull()
        ->and(BookletLink::query()->whereNull('closed_at')->count())->toBe(1)
        ->and(bookletRun('2027-02-09'))->toBe(0)
        ->and(bookletRun('2027-02-15'))->toBe(1)
        ->and(bookletRun('2027-02-22'))->toBe(0);
});

it('sends one reminder when catching up both, and none once signed', function () {
    bookletRun('2027-02-01');
    expect(bookletRun('2027-02-20'))->toBe(1)->and(bookletRun('2027-02-21'))->toBe(0);

    $c = bookletApprentice();
    Person::query()->whereKey($c->person_id)->update(['email' => 'autre@example.test']);
    bookletRun('2027-02-01');
    BookletMonths::page($c, '2027-01', 'company')->forceFill(['apprentice_signed_at' => now()])->save();
    BookletMonths::page($c, '2027-01', 'centre')->forceFill(['apprentice_signed_at' => now()])->save();
    expect(BookletLink::query()->where('candidature_id', $c->id)->where('signer', 'apprentice')->count())->toBe(1);
    bookletRun('2027-02-08');
    expect(BookletLink::query()->where('candidature_id', $c->id)->where('signer', 'apprentice')->count())->toBe(1);
});
