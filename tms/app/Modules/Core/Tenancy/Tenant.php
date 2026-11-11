<?php

namespace App\Modules\Core\Tenancy;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation using the software (organisme). Platform table: not tenant-scoped.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property array<string, mixed> $settings
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'slug', 'status', 'settings'];

    protected $attributes = [
        'status' => 'active',
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<TenantDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }
}
