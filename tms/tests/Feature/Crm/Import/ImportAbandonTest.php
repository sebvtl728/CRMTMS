<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\ImportAbandon;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\SimulateImport;
use App\Modules\Crm\Import\ValueMapper;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const ABANDON_CSV = "Raison sociale;Ville\nGarage Martin;Pau\nCafé des Arts;Lyon\n";

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

it('abandons an uploaded or simulated import: batch, rows and file are deleted, no fiche is touched', function (bool $simulate) {
    $batch = uploadImport(ABANDON_CSV, ImportTarget::Organisations);
    if ($simulate) {
        simulateImport($batch);
        DB::table('import_rows')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => tenant()->id, 'batch_id' => $batch->id, 'line' => 2, 'status' => ImportRow::SKIPPED, 'source' => json_encode(['x' => 'y'])]);
    }
    $path = $batch->stored_path;
    expect(Storage::disk(TenantStorage::DISK)->exists($path))->toBeTrue();

    $this->delete("/crm/import/{$batch->id}")->assertRedirect('/crm/import');

    expect(ImportBatch::query()->count())->toBe(0)
        ->and(DB::table('import_rows')->where('batch_id', $batch->id)->count())->toBe(0)
        ->and(Storage::disk(TenantStorage::DISK)->exists($path))->toBeFalse()
        ->and(Organisation::query()->count())->toBe(0);
    $this->get('/crm/import')->assertInertia(fn ($page) => $page->has('batches.data', 0));
})->with([false, true]);

it('journals the abandon without any file content', function () {
    $batch = uploadImport(ABANDON_CSV, ImportTarget::Organisations, [], 'Dupont-clients.csv');
    $this->delete("/crm/import/{$batch->id}");

    $logs = AuditLog::query()->where('event', 'like', '%import%')->get()->filter(fn ($l) => str_contains($l->event, 'abandon') || $l->event === 'deleted');
    expect($logs)->not->toBeEmpty();
    $dump = json_encode(AuditLog::query()->orderByDesc('created_at')->limit(5)->get()->toArray());
    expect($dump)->not->toContain('Garage Martin')->and($dump)->not->toContain('Dupont')
        ->and(AuditLog::query()->where('event', 'crm.import_abandoned')->count())->toBe(1);
});

it('refuses to abandon an import that ran, with a French message and nothing deleted', function (string $status) {
    $batch = uploadImport(ABANDON_CSV, ImportTarget::Organisations);
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => $status]);

    $this->delete("/crm/import/{$batch->id}")->assertStatus(409)->assertSee('ne peut plus être abandonné');

    expect(ImportBatch::query()->count())->toBe(1)
        ->and(Storage::disk(TenantStorage::DISK)->exists($batch->stored_path))->toBeTrue();
})->with(['running', 'done', 'failed', 'undone', 'undoing']);

it('answers 404 for the import of another user without the all-imports right, and lets an administrator abandon it', function () {
    $batch = uploadImport(ABANDON_CSV, ImportTarget::Organisations);

    $this->actingAs(commercial());
    $this->delete("/crm/import/{$batch->id}")->assertNotFound();
    expect(ImportBatch::query()->count())->toBe(1);

    $this->actingAs(admin(true));
    $this->delete("/crm/import/{$batch->id}")->assertRedirect('/crm/import');
    expect(ImportBatch::query()->count())->toBe(0);
});

it('stops a simulation whose batch was abandoned, without error or orphan row', function () {
    $batch = uploadImport(ABANDON_CSV, ImportTarget::Organisations);
    $batch->startSimulation(FieldMap::guess($batch->target, $batch->reader()->headers()), new ValueMapper);
    expect($batch->refresh()->status)->toBe(ImportBatch::SIMULATING);

    ImportAbandon::run($batch);
    SimulateImport::dispatch($batch->id);

    expect(ImportBatch::query()->count())->toBe(0)->and(DB::table('import_rows')->count())->toBe(0);
});
