<?php

namespace Tests;

use App\Modules\Core\Tenancy\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Web routes resolve a tenant (single mode by default): provide the
        // default one, inside the per-test transaction.
        Tenant::factory()->create(['slug' => config('tms.default_tenant')]);
    }
}
