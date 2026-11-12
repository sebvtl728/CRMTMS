<?php

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

it('runs the callback once per active tenant with its context', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    $suspended = Tenant::factory()->suspended()->create();
    $seen = [];

    ForEachTenant::run(function (Tenant $tenant) use (&$seen) {
        $seen[$tenant->id] = [app(TenantContext::class)->id(), dbTenantSetting()];
    });

    $default = Tenant::query()->where('slug', config('tms.default_tenant'))->sole();

    expect(array_keys($seen))->toEqualCanonicalizing([$default->id, $a->id, $b->id])
        ->and($seen[$a->id])->toBe([$a->id, $a->id])
        ->and($seen[$b->id])->toBe([$b->id, $b->id])
        ->and($seen)->not->toHaveKey($suspended->id)
        ->and(app(TenantContext::class)->current())->toBeNull()
        ->and(dbTenantSetting())->toBe('');
});

it('includes suspended tenants on request', function () {
    $suspended = Tenant::factory()->suspended()->create();
    $seen = [];

    ForEachTenant::run(function (Tenant $tenant) use (&$seen) {
        $seen[] = $tenant->id;
    }, includeSuspended: true);

    expect($seen)->toContain($suspended->id);
});

it('continues after one tenant fails', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });
    $seen = [];

    ForEachTenant::run(function (Tenant $tenant) use (&$seen, $a) {
        if ($tenant->id === $a->id) {
            throw new RuntimeException('boom');
        }
        $seen[] = $tenant->id;
    });

    expect($seen)->toContain($b->id)->not->toContain($a->id)
        ->and(app(TenantContext::class)->current())->toBeNull()
        ->and(dbTenantSetting())->toBe('');

    $errors = array_values(array_filter($logged, fn (MessageLogged $e) => $e->level === 'error'));
    expect($errors)->toHaveCount(1)
        ->and($errors[0]->context['tenant_id'])->toBe($a->id)
        ->and($errors[0]->context['exception'])->toBeInstanceOf(RuntimeException::class);
});

it('restores the context that was active before', function () {
    $outer = inTenant();
    Tenant::factory()->create();

    ForEachTenant::run(fn () => null);

    expect(app(TenantContext::class)->id())->toBe($outer->id)
        ->and(dbTenantSetting())->toBe($outer->id);
});
