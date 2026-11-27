<?php

use App\Modules\Finance\Invoices\Due;
use App\Modules\Finance\Invoices\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * Three dossiers: one due (the OPCO and the contractor), one « bientôt à
 * facturer » (September not closed), one whose invoice is validated.
 */
function dqSeedThree(): void
{
    fiSeed();
    fiSeed(close: false);
    $x = fiSeed(opco: false);
    fiDrafts()->prepare(fiManager(), array_column(fiDue($x['dossier']->id), 'key'));
    fiValidate(Invoice::query()->where('dossier_id', $x['dossier']->id)->sole());
}

function dqCount(callable $run): int
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

it('reads what is due with a number of queries independent of the number of dossiers', function () {
    $manager = fiManager();
    dqSeedThree();
    app(Due::class)->forUser($manager); // permissions and settings read once
    $few = dqCount(fn () => app(Due::class)->forUser($manager));
    $fewRows = app(Due::class)->forUser($manager);

    dqSeedThree();
    dqSeedThree();
    app(Due::class)->forUser($manager);
    $many = dqCount(fn () => app(Due::class)->forUser($manager));
    $manyRows = app(Due::class)->forUser($manager);

    expect(count($fewRows['rows']))->toBe(2)->and(count($fewRows['soon']))->toBe(1)
        ->and(count($manyRows['rows']))->toBe(6)->and(count($manyRows['soon']))->toBe(3)
        ->and($many)->toBe($few);
});

it('serves « À facturer » and « À traiter » with a number of queries independent of the number of dossiers', function () {
    $manager = fiManager();
    dqSeedThree();
    $page = fn () => $this->actingAs($manager)->get('/finance/to-invoice')->assertOk();
    $todo = fn () => $this->actingAs($manager)->get('/a-traiter')->assertOk();
    $page();
    $todo();
    $fewPage = dqCount($page);
    $fewTodo = dqCount($todo);

    dqSeedThree();
    dqSeedThree();
    $page();
    $todo();
    $manyPage = dqCount($page);
    $manyTodo = dqCount($todo);

    expect($manyPage)->toBe($fewPage)->and($manyTodo)->toBe($fewTodo);
});
