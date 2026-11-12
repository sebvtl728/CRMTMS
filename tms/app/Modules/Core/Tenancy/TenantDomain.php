<?php

namespace App\Modules\Core\Tenancy;

use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Host name (sub-domain or custom domain) pointing to a tenant. Platform table:
 * it is read to resolve the tenant, before any tenant context exists.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $host
 * @property-read Tenant $tenant
 */
#[UseFactory(TenantDomainFactory::class)]
class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'host'];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Hosts are compared case-insensitively (Request::getHost() is lower-case).
     *
     * @return Attribute<string, string>
     */
    protected function host(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }
}
