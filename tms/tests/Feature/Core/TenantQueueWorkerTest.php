<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ContextSnapshotJob;
use Tests\Fixtures\LeakyTenantJob;
use Tests\Fixtures\ProbeItem;
use Tests\Fixtures\ProbeItemJob;

/*
 * Real worker path: jobs are pushed to the database queue, then processed by
 * queue:work in a process state without ambient tenant (as on Horizon).
 */

function workQueue(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
}

beforeEach(function () {
    ProbeItemJob::$seenLabel = null;
    ContextSnapshotJob::$ran = false;
    ContextSnapshotJob::$seenTenantId = null;
    ContextSnapshotJob::$seenSetting = null;
});

it('hydrates a tenant model carried by a queued job on a worker without ambient context', function () {
    $a = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->run($a, function () {
        $item = ProbeItem::create(['label' => 'facture A']);
        ProbeItemJob::dispatch($item)->onConnection('database');
    });

    expect($context->current())->toBeNull();

    workQueue();

    expect(ProbeItemJob::$seenLabel)->toBe('facture A')
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($context->current())->toBeNull()
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as v")->v)->toBe('');
});

it('does not leak a tenant set by one job into the next job', function () {
    $a = Tenant::factory()->create();

    LeakyTenantJob::dispatch($a->id)->onConnection('database');
    ContextSnapshotJob::dispatch()->onConnection('database');

    workQueue();

    expect(ContextSnapshotJob::$ran)->toBeTrue()
        ->and(ContextSnapshotJob::$seenTenantId)->toBeNull()
        ->and(ContextSnapshotJob::$seenSetting)->toBe('');
});

it('keeps the caller context after a sync job', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    $context = app(TenantContext::class);
    $context->set($a);

    LeakyTenantJob::dispatchSync($b->id);

    expect($context->id())->toBe($a->id)
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as v")->v)->toBe($a->id);
});
