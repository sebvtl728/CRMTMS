<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportFileException;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\RunImport;
use App\Modules\Crm\Import\SimulateImport;
use App\Modules\Crm\Import\Simulator;
use App\Modules\Crm\Import\UndoImport;
use App\Modules\Crm\Import\ValueMapper;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

/** Moves the last progress of a batch back in time (a worker that died). */
function ageBatch(ImportBatch $batch, int $minutes): void
{
    DB::table('import_batches')->where('id', $batch->id)->update(['updated_at' => now()->subMinutes($minutes)]);
}

it('simulates in the background: status simulating, then simulated with the details kept', function () {
    $batch = uploadImport("Raison sociale;Ville\nGarage Martin;Pau\n;Paris\nCafé des Arts;Lyon\n", ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, $batch->reader()->headers()), new ValueMapper);

    expect($batch->refresh()->status)->toBe(ImportBatch::SIMULATING)
        ->and($batch->field_map)->toEqual(['Raison sociale' => 'name', 'Ville' => 'city'])
        ->and($batch->simulation)->toBeNull();

    SimulateImport::dispatch($batch->id);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::SIMULATED)
        ->and($batch->simulation['to_create'])->toBe(2)
        ->and($batch->simulation['errors'])->toBe(1)
        ->and($batch->simulation['error_rows'])->toHaveCount(1)
        ->and($batch->simulation['error_rows'][0]['line'])->toBe(3)
        ->and($batch->simulation['error_rows'][0]['messages'][0])->toContain('Raison sociale')
        ->and(Organisation::query()->count())->toBe(0);
});

it('keeps at most the first 100 error lines', function () {
    $csv = "Raison sociale;Ville\n".str_repeat(";Paris\n", 130);
    $batch = uploadImport($csv, ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, $batch->reader()->headers()), new ValueMapper);
    SimulateImport::dispatch($batch->id);

    $batch->refresh();
    expect($batch->simulation['errors'])->toBe(130)
        ->and($batch->simulation['error_rows'])->toHaveCount(100)
        ->and($batch->simulation['error_rows'][99]['line'])->toBe(101);
});

it('reports the progress of a simulation and goes back to uploaded when the file is gone', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, ['Raison sociale']), new ValueMapper);
    Storage::disk(TenantStorage::DISK)->delete($batch->stored_path);

    SimulateImport::dispatch($batch->id);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::UPLOADED)
        ->and($batch->options['simulation_error'])->toContain('plus disponible');
});

it('does not let a late simulation overwrite a batch marked as failed', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, ['Raison sociale']), new ValueMapper);
    ageBatch($batch, 20);
    $batch->refresh()->markFailed($this->user);

    SimulateImport::dispatch($batch->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::UPLOADED)->and($batch->simulation)->toBeNull();
});

it('sends a stuck simulation back to the mapping step with an explanation instead of a dead end', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, ['Raison sociale']), new ValueMapper);
    ageBatch($batch, 20);

    $this->post("/crm/import/{$batch->id}/fail")->assertRedirect("/crm/import/{$batch->id}");

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::UPLOADED)
        ->and($batch->simulation)->toBeNull()
        ->and($batch->options['simulation_error'])->toContain('simulation ne progressait plus')
        ->and($batch->field_map)->toEqual(['Raison sociale' => 'name'])
        ->and(AuditLog::query()->where('event', 'crm.import_failed_marked')->count())->toBe(1);
    $this->get("/crm/import/{$batch->id}")->assertRedirect("/crm/import/{$batch->id}/mapping");
    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn ($page) => $page->where('error', $batch->options['simulation_error']));
});

it('retries a simulation later when another worker holds the batch', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, ['Raison sociale']), new ValueMapper);
    $lock = Cache::lock('crm-import:'.tenant()->id.':'.$batch->id, 60);
    expect($lock->get())->toBeTrue();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->once()->with(5);
    $job->shouldReceive('isReleased', 'isDeleted', 'hasFailed')->andReturn(false);
    $simulate = new SimulateImport($batch->id);
    $simulate->setJob($job);
    app()->call([$simulate, 'handle']);

    expect($batch->refresh()->status)->toBe(ImportBatch::SIMULATING);
    $lock->release();
});

it('refuses to simulate or start a batch that already ran', function () {
    $batch = runImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);

    expect($batch->status)->toBe(ImportBatch::DONE)
        ->and($batch->startSimulation(FieldMap::guess($batch->target, ['Raison sociale']), new ValueMapper))->toBeFalse()
        ->and($batch->refresh()->status)->toBe(ImportBatch::DONE)
        ->and(fn () => app(Simulator::class)->run($batch, $batch->fieldMap(), $batch->valueMapper()))->toThrow(ImportFileException::class);
});

it('starts a run: status running at once with fresh counters, then the job completes it', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\nCafé des Arts\n", ImportTarget::Organisations);
    simulateImport($batch);
    $batch->refresh();
    expect($batch->canRun())->toBeTrue();

    $batch->start();
    expect($batch->refresh()->status)->toBe(ImportBatch::RUNNING)
        ->and([$batch->total, $batch->created, $batch->cursor_line])->toBe([0, 0, 0])
        ->and($batch->started_at)->not->toBeNull()
        ->and($batch->simulation)->not->toHaveKey('error_rows'); // raw lines are not kept once the run starts

    RunImport::dispatch($batch->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::DONE)->and([$batch->total, $batch->created])->toBe([2, 2]);
});

it('detects a batch without progress for 15 minutes', function (string $status, int $minutes, bool $stuck) {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => $status]);
    ageBatch($batch, $minutes);

    expect($batch->refresh()->isStuck())->toBe($stuck);
})->with([
    'running 16 min' => [ImportBatch::RUNNING, 16, true],
    'running 14 min' => [ImportBatch::RUNNING, 14, false],
    'simulating 16 min' => [ImportBatch::SIMULATING, 16, true],
    'simulating 2 min' => [ImportBatch::SIMULATING, 2, false],
    'done long ago' => [ImportBatch::DONE, 600, false],
    'uploaded long ago' => [ImportBatch::UPLOADED, 600, false],
]);

it('marks a stuck run as failed: what it created can then be undone', function () {
    $batch = runImport("Raison sociale\nGarage Martin\nCafé des Arts\n", ImportTarget::Organisations);
    // The worker died after the first chunk: the batch never reached « done ».
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => ImportBatch::RUNNING, 'finished_at' => null]);
    ageBatch($batch, 30);
    $batch->refresh();
    expect($batch->canUndo())->toBeFalse();

    expect($batch->markFailed($this->user))->toBeTrue();

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::FAILED)
        ->and($batch->finished_at)->not->toBeNull()
        ->and($batch->options['failure'])->toContain('Marqué comme échoué')
        ->and($batch->canUndo())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'crm.import_failed_marked')->sole()->user_id)->toBe($this->user->id);

    UndoImport::dispatch($batch->id, $this->user->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)
        ->and(Organisation::query()->count())->toBe(0)
        ->and(Organisation::onlyTrashed()->count())->toBe(2);
});

it('refuses to mark a batch that is progressing or finished as failed', function (string $status, int $minutes) {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => $status]);
    ageBatch($batch, $minutes);

    expect($batch->refresh()->markFailed($this->user))->toBeFalse()
        ->and($batch->refresh()->status)->toBe($status);
})->with([
    'running 5 min' => [ImportBatch::RUNNING, 5],
    'done' => [ImportBatch::DONE, 600],
]);

it('starts an undo at once so the page shows it', function () {
    $batch = runImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);

    $batch->startUndo();
    expect($batch->refresh()->status)->toBe(ImportBatch::UNDOING)->and($batch->options['undo'])->toEqual(['removed' => 0, 'kept' => 0]);

    UndoImport::dispatch($batch->id, $this->user->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)->and($batch->options['undo'])->toEqual(['removed' => 1, 'kept' => 0]);
});

it('queues the simulation on the long-running queue, whose worker outlives the longest simulation', function () {
    config(['queue.default' => 'redis']);

    $job = new SimulateImport('019aaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa');

    expect($job->connection)->toBe('redis-long')->and($job->queue)->toBe('imports')
        ->and(config('queue.connections.redis-long.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('horizon.defaults.supervisor-imports'))->toMatchArray(['connection' => 'redis-long', 'queue' => ['imports'], 'timeout' => $job->timeout])
        // The other jobs keep their 60 s worker timeout.
        ->and(config('horizon.defaults.supervisor-1.timeout'))->toBe(60);
});

it('never turns a batch that started running in the meantime back into a simulation', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    simulateImport($batch);
    $batch->refresh();
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => ImportBatch::RUNNING]);

    $started = $batch->startSimulation(FieldMap::guess($batch->target, ['Raison sociale']), new ValueMapper);

    expect($started)->toBeFalse()
        ->and($batch->refresh()->status)->toBe(ImportBatch::RUNNING)
        ->and($batch->simulation)->not->toBeNull();
});

it('answers « Cet import a déjà été lancé. » when the run starts while the strategy is saved', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    simulateImport($batch);
    // Another tab starts the run right after this request loaded the batch.
    ImportBatch::retrieved(function (ImportBatch $b) {
        DB::table('import_batches')->where('id', $b->id)->update(['status' => ImportBatch::RUNNING]);
    });
    Queue::fake();

    $this->put("/crm/import/{$batch->id}/strategy", ['strategy' => 'skip'])
        ->assertRedirect("/crm/import/{$batch->id}")
        ->assertSessionHasErrors(['batch' => 'Cet import a déjà été lancé.']);

    expect(DB::table('import_batches')->where('id', $batch->id)->value('status'))->toBe(ImportBatch::RUNNING);
    Queue::assertNotPushed(SimulateImport::class);
});
