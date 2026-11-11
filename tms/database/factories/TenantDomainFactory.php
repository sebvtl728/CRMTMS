<?php

namespace Database\Factories;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'host' => fake()->unique()->domainWord().'.tms.test',
        ];
    }
}
