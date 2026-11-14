<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\ImportWatchdog;
use App\Modules\Crm\Import\RunImport;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

/**
 * A run whose worker died after line $doneLine: status running, cursor on
 * that line, no progress for $minutes.
 */
function deadRun(int $doneLine, int $minutes, array $options = []): ImportBatch
{
    $batch = uploadImport("Raison sociale\nGarage Martin\nCafé des Arts\nHôtel du Lac\n", ImportTarget::Organisations);
    simulateImport($batch);
    $batch->refresh()->start();
    DB::table('import_batches')->where('id', $batch->id)->update([
        'cursor_line' => $doneLine, 'updated_at' => now()->subMinutes($minutes),
        'options' => json_encode([...$batch->options, ...$options]),
    ]);

    return $batch->refresh();
}

it('resumes once, from its cursor, a run without progress for 15 minutes', function () {
    $batch = deadRun(2, 16);

    expect(app(ImportWatchdog::class)->run())->toBe(['resumed' => 1, 'failed' => 0]);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::DONE)
        ->and($batch->options['resumes'])->toBe(1)
        // Line 2 (Garage Martin) was done before the worker died: not imported again.
        ->and(Organisation::query()->orderBy('name')->pluck('name')->all())->toBe(['Café des Arts', 'Hôtel du Lac'])
        ->and(AuditLog::query()->where('event', 'crm.import_resumed')->sole()->new_values)->toMatchArray(['cursor_line' => 2, 'resumes' => 1]);
});

it('leaves alone a run that progressed in the last 15 minutes, or is finished', function (string $status, int $minutes) {
    $batch = deadRun(2, $minutes);
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => $status]);

    expect(app(ImportWatchdog::class)->run())->toBe(['resumed' => 0, 'failed' => 0])
        ->and($batch->refresh()->status)->toBe($status)
        ->and($batch->options)->not->toHaveKey('resumes');
})->with([
    'running 14 min' => [ImportBatch::RUNNING, 14],
    'done long ago' => [ImportBatch::DONE, 600],
    'failed long ago' => [ImportBatch::FAILED, 600],
]);

it('marks the run failed with a french reason when it stalls again after its resume', function () {
    $batch = deadRun(2, 16, ['resumes' => 1]);

    expect(app(ImportWatchdog::class)->run())->toBe(['resumed' => 0, 'failed' => 1]);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::FAILED)
        ->and($batch->finished_at)->not->toBeNull()
        ->and($batch->options['failure'])->toBe('L\'import ne progressait plus : il a été relancé automatiquement une fois, sans succès, puis arrêté. Les lignes déjà traitées sont conservées (annulables).')
        ->and($batch->canUndo())->toBeTrue()
        ->and(Organisation::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'crm.import_failed_marked')->sole()->new_values)->toMatchArray(['watchdog' => true]);
});

it('never starts a second runner while the original job still holds the batch', function () {
    $batch = deadRun(2, 16);
    $lock = Cache::lock(RunImport::lockKey(tenant()->id, $batch->id), 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(app(ImportWatchdog::class)->run())->toBe(['resumed' => 0, 'failed' => 0]);
    } finally {
        $lock->release();
    }

    expect($batch->refresh()->status)->toBe(ImportBatch::RUNNING)
        ->and($batch->options)->not->toHaveKey('resumes')
        ->and(Organisation::query()->count())->toBe(0);
});

it('runs every 10 minutes for every organisme', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'tms:crm:import-watchdog'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/10 * * * *');

    $batch = deadRun(2, 16);
    $mine = tenant();
    Tenant::factory()->create();

    expect(Artisan::call('tms:crm:import-watchdog'))->toBe(0);

    inTenant($mine);
    expect($batch->refresh()->status)->toBe(ImportBatch::DONE)
        ->and(Artisan::output())->toContain('Imports relancés : 1, marqués échoués : 0.');
});
