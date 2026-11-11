<?php

namespace Database\Factories;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'settings' => [],
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }
}
