<?php

namespace Tests;

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /**
     * SHA-1 suffix lines served by the faked Have I Been Pwned range API
     * (see markPasswordAsCompromised()).
     *
     * @var list<string>
     */
    private array $pwnedPasswordLines = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Web routes resolve a tenant (single mode by default): provide the
        // default one, inside the per-test transaction.
        Tenant::factory()->create(['slug' => config('tms.default_tenant')]);

        // Password::defaults() uses uncompromised(): never hit the real HIBP API.
        Http::preventStrayRequests();
        Http::fake([
            'api.pwnedpasswords.com/*' => fn () => Http::response(implode("\r\n", $this->pwnedPasswordLines)),
        ]);
    }

    /**
     * Makes the faked HIBP range API report $password as leaked.
     */
    protected function markPasswordAsCompromised(string $password): void
    {
        $this->pwnedPasswordLines[] = substr(strtoupper(sha1($password)), 5).':4242';
    }

    /**
     * A request terminates by clearing the tenant context (ResolveTenant). The
     * test body is not part of the request: give it back the context it had,
     * as if it were a separate process (console command) acting for a tenant.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<mixed>  $parameters
     * @param  array<mixed>  $cookies
     * @param  array<mixed>  $files
     * @param  array<mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $context = $this->app->make(TenantContext::class);
        $previous = $context->current();
        // Each request is a fresh PHP process in production: no memoised scope.
        Scopes::forget();

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $previous !== null ? $context->set($previous) : $context->clear();
        }
    }

    /**
     * Forgets the in-memory authenticated user and session store, so the next
     * request starts like a fresh PHP process and only knows what its cookies
     * carry (otherwise the test kernel reuses the previous request's guard).
     */
    protected function freshProcess(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make('session')->forgetDrivers();
    }
}
