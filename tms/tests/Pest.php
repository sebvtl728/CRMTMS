<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantDomain;
use App\Modules\Core\Users\User;
use Tests\RefreshDatabaseAsOwner;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabaseAsOwner::class)
    ->in('Feature', 'Unit', 'Arch');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sets the tenant context for the test body (default tenant when null) and
 * returns the tenant. Requests restore it afterwards (Tests\TestCase::call).
 */
function inTenant(?Tenant $tenant = null): Tenant
{
    $tenant ??= Tenant::query()->where('slug', config('tms.default_tenant'))->sole();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/**
 * A tenant reachable on $host (multi mode).
 */
function tenantOnHost(string $host): Tenant
{
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['host' => $host]);

    return $tenant;
}

/**
 * Creates a user inside $tenant without changing the current context.
 *
 * @param  array<string, mixed>  $attributes
 */
function userIn(Tenant $tenant, array $attributes = []): User
{
    return app(TenantContext::class)
        ->run($tenant, fn () => User::factory()->create($attributes));
}
