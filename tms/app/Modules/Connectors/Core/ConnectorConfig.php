<?php

namespace App\Modules\Connectors\Core;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration of one connector for an organisme, optionally for one of its
 * companies (company_id null = whole organisme). Unique per
 * (tenant, connector_key, company), NULL company included.
 *
 * Secrets are stored encrypted with APP_KEY, are hidden from serialisation,
 * and are masked in the audit journal.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_key
 * @property string|null $company_id
 * @property bool $enabled
 * @property array<string, mixed> $settings
 * @property array<string, string>|null $secrets
 */
class ConnectorConfig extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** @var list<string> */
    protected $fillable = ['connector_key', 'company_id', 'enabled', 'settings', 'secrets'];

    /** @var list<string> */
    protected $hidden = ['secrets'];

    /** @var list<string> */
    protected array $auditExclude = ['secrets'];

    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => false, 'settings' => '{}'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'secrets' => 'encrypted:array',
        ];
    }

    /**
     * Which secrets hold a value, never the values: {"password": true}.
     *
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public function secretsSet(array $keys): array
    {
        $stored = $this->secrets ?? [];

        return collect($keys)->mapWithKeys(fn (string $k) => [$k => ($stored[$k] ?? '') !== ''])->all();
    }

    public function secret(string $key): ?string
    {
        $value = ($this->secrets ?? [])[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ConnectorRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ConnectorRun::class);
    }
}
