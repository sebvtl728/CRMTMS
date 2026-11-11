<?php

use App\Modules\Core\Tenancy\MissingTenantContext;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\ProbeItem;
use Tests\Fixtures\ProbeJob;

function tenantContext(): TenantContext
{
    return app(TenantContext::class);
}

function dbTenantSetting(): ?string
{
    return DB::selectOne("select current_setting('app.tenant_id', true) as v")->v;
}

/**
 * @return array{0: Tenant, 1: Tenant}
 */
function twoTenantsWithItems(): array
{
    [$a, $b] = Tenant::factory()->count(2)->create();
    tenantContext()->run($a, fn () => ProbeItem::create(['label' => 'A']));
    tenantContext()->run($b, fn () => ProbeItem::create(['label' => 'B']));

    return [$a, $b];
}

beforeEach(function () {
    ProbeJob::$seenCount = null;
    ProbeJob::$seenRawCount = null;
    ProbeJob::$seenTenantId = null;
});

it('scopes eloquent queries to the current tenant', function () {
    [$a] = twoTenantsWithItems();

    tenantContext()->run($a, fn () => expect(ProbeItem::pluck('label')->all())->toBe(['A']));
});

it('isolates at database level even without the eloquent scope', function () {
    [$a, $b] = twoTenantsWithItems();

    tenantContext()->run($a, fn () => expect(DB::table('probe_items')->pluck('label')->all())->toBe(['A']));
    tenantContext()->run($b, fn () => expect(ProbeItem::withoutGlobalScopes()->pluck('label')->all())->toBe(['B']));
});

it('query without context returns nothing', function () {
    twoTenantsWithItems();

    tenantContext()->clear();

    expect(DB::table('probe_items')->count())->toBe(0)
        ->and(ProbeItem::count())->toBe(0)
        ->and(ProbeItem::withoutGlobalScopes()->count())->toBe(0);
});

it('cannot find, update or delete another tenant row', function () {
    [$a, $b] = twoTenantsWithItems();
    $bItemId = tenantContext()->run($b, fn () => ProbeItem::sole()->id);

    tenantContext()->run($a, function () use ($bItemId) {
        expect(ProbeItem::find($bItemId))->toBeNull()
            ->and(DB::table('probe_items')->where('id', $bItemId)->update(['label' => 'pwned']))->toBe(0)
            ->and(DB::table('probe_items')->where('id', $bItemId)->delete())->toBe(0);
    });

    tenantContext()->run($b, fn () => expect(ProbeItem::sole()->label)->toBe('B'));
});

it('refuses to insert a row for another tenant', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    tenantContext()->set($a);

    DB::table('probe_items')->insert(['id' => Str::uuid7(), 'tenant_id' => $b->id, 'label' => 'x']);
})->throws(QueryException::class, 'row-level security');

it('refuses to insert a row without tenant context', function () {
    $a = Tenant::factory()->create();

    DB::table('probe_items')->insert(['id' => Str::uuid7(), 'tenant_id' => $a->id, 'label' => 'x']);
})->throws(QueryException::class, 'row-level security');

it('refuses to move a row to another tenant', function () {
    [$a, $b] = twoTenantsWithItems();
    tenantContext()->set($a);

    DB::table('probe_items')->update(['tenant_id' => $b->id]);
})->throws(QueryException::class, 'row-level security');

it('fills tenant_id from the context on create', function () {
    $a = Tenant::factory()->create();

    $item = tenantContext()->run($a, fn () => ProbeItem::create(['label' => 'A']));

    expect($item->tenant_id)->toBe($a->id)
        ->and($item->tenant->is($a))->toBeTrue()
        ->and(Str::isUuid($item->id))->toBeTrue()
        ->and(substr($item->id, 14, 1))->toBe('7');
});

it('creating a tenant model without context throws', function () {
    ProbeItem::create(['label' => 'orphan']);
})->throws(MissingTenantContext::class);

it('refuses to create a model explicitly assigned to another tenant', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();

    tenantContext()->run($a, function () use ($b) {
        $item = new ProbeItem(['label' => 'x']);
        $item->tenant_id = $b->id;
        $item->save();
    });
})->throws(LogicException::class);

it('jobs carry tenant context', function () {
    [$a] = twoTenantsWithItems();

    tenantContext()->run($a, fn () => ProbeJob::dispatchSync());

    expect(ProbeJob::$seenCount)->toBe(1);
});

it('jobs run in the tenant captured at construction, not the dispatching context', function () {
    [$a, $b] = twoTenantsWithItems();
    tenantContext()->run($a, fn () => ProbeItem::create(['label' => 'A2']));

    $job = tenantContext()->run($a, fn () => new ProbeJob);

    tenantContext()->clear();
    dispatch_sync($job);
    expect(ProbeJob::$seenTenantId)->toBe($a->id)
        ->and(ProbeJob::$seenCount)->toBe(2)
        ->and(ProbeJob::$seenRawCount)->toBe(2)
        ->and(tenantContext()->current())->toBeNull()
        ->and(dbTenantSetting())->toBe('');

    tenantContext()->run($b, fn () => dispatch_sync($job));
    expect(ProbeJob::$seenTenantId)->toBe($a->id)
        ->and(tenantContext()->current())->toBeNull();
});

it('a tenant-aware job cannot be built without context', function () {
    new ProbeJob;
})->throws(MissingTenantContext::class);

it('id() throws without context while current() returns null', function () {
    expect(tenantContext()->current())->toBeNull();

    tenantContext()->id();
})->throws(MissingTenantContext::class);

it('set and clear drive the postgres session setting', function () {
    $a = Tenant::factory()->create();

    tenantContext()->set($a);
    expect(dbTenantSetting())->toBe($a->id)
        ->and(tenantContext()->id())->toBe($a->id);

    tenantContext()->clear();
    expect(dbTenantSetting())->toBe('')
        ->and(tenantContext()->current())->toBeNull();
});

it('run restores the previous context, even when the callback throws', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    tenantContext()->set($a);

    expect(tenantContext()->run($b, fn () => tenantContext()->id()))->toBe($b->id)
        ->and(tenantContext()->id())->toBe($a->id)
        ->and(dbTenantSetting())->toBe($a->id);

    try {
        tenantContext()->run($b, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(tenantContext()->id())->toBe($a->id)
        ->and(dbTenantSetting())->toBe($a->id);

    tenantContext()->clear();
    tenantContext()->run($b, fn () => null);
    expect(tenantContext()->current())->toBeNull()
        ->and(dbTenantSetting())->toBe('');
});

it('re-applies the tenant setting when a rolled back transaction reverted it', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    tenantContext()->set($a);

    DB::beginTransaction();
    tenantContext()->set($b);
    DB::rollBack(); // postgres reverts set_config() to tenant A here

    expect(tenantContext()->id())->toBe($b->id)
        ->and(dbTenantSetting())->toBe($b->id);
});

it('restores the previous context when the callback aborts the transaction', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    tenantContext()->set($a);

    DB::beginTransaction();
    try {
        // The failing query aborts the transaction, so run() cannot write the setting back.
        tenantContext()->run($b, fn () => DB::select('select 1/0'));
    } catch (QueryException) {
    }
    DB::rollBack();

    expect(tenantContext()->id())->toBe($a->id)
        ->and(dbTenantSetting())->toBe($a->id);
});

it('re-applies the tenant setting when the connection is re-established', function () {
    $a = Tenant::factory()->create();
    tenantContext()->set($a);

    // Simulate a fresh session (what a reconnect gives us) then the framework event.
    DB::select("select set_config('app.tenant_id', '', false)");
    event(new ConnectionEstablished(DB::connection('pgsql')));

    expect(dbTenantSetting())->toBe($a->id);
});
