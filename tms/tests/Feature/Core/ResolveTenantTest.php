<?php

use App\Modules\Core\Tenancy\ResolveTenant;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantDomain;
use App\Modules\Core\Tenancy\TenantStatus;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/tenant', fn (TenantContext $context) => $context->id());
});

it('resolves the default tenant in single mode', function () {
    config(['tms.mode' => 'single']);
    $default = Tenant::where('slug', config('tms.default_tenant'))->sole();

    $this->get('/_test/tenant')->assertOk()->assertSee($default->id);
});

it('answers 404 in single mode when the default tenant does not exist', function () {
    config(['tms.mode' => 'single', 'tms.default_tenant' => 'absent']);

    $this->get('/_test/tenant')->assertNotFound()->assertSee('Organisme introuvable');
});

it('resolves the tenant from the request host in multi mode', function () {
    config(['tms.mode' => 'multi']);
    [$a, $b] = Tenant::factory()->count(2)->create();
    TenantDomain::factory()->for($a)->create(['host' => 'a.tms.test']);
    TenantDomain::factory()->for($b)->create(['host' => 'B.TMS.test']);

    $this->get('http://a.tms.test/_test/tenant')->assertOk()->assertSee($a->id);
    $this->get('http://b.tms.test/_test/tenant')->assertOk()->assertSee($b->id);
});

it('answers 404 for an unknown host in multi mode', function () {
    config(['tms.mode' => 'multi']);

    $this->get('http://inconnu.tms.test/_test/tenant')->assertNotFound()->assertSee('Organisme introuvable');
});

it('answers 503 « Organisme suspendu » for a suspended tenant', function () {
    config(['tms.mode' => 'multi']);
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    TenantDomain::factory()->for($tenant)->create(['host' => 'suspendu.tms.test']);

    $this->get('http://suspendu.tms.test/_test/tenant')->assertStatus(503)->assertSee('Organisme suspendu');
});

it('clears the tenant context once the request terminates', function () {
    config(['tms.mode' => 'single']);

    $this->get('/_test/tenant')->assertOk();

    expect(app(TenantContext::class)->current())->toBeNull()
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as v")->v)->toBe('');
});

it('does not apply to /health', function () {
    Cache::store('file')->flush();
    config(['tms.mode' => 'multi']);

    $this->getJson('http://inconnu.tms.test/health')->assertOk();
});

it('runs before authentication', function () {
    app(HttpKernel::class); // registers the middleware groups on the router
    $route = app(Router::class)->getRoutes()->getByName('dashboard');
    $middleware = app(Router::class)->gatherRouteMiddleware($route);

    $resolve = array_search(ResolveTenant::class, $middleware, true);
    $auth = collect($middleware)->search(fn ($m) => str_starts_with($m, Authenticate::class));

    expect($resolve)->toBe(0)
        ->and($auth)->toBeGreaterThan($resolve);
});
