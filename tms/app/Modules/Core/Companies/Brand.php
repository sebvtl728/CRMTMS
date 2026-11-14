<?php

namespace App\Modules\Core\Companies;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The identity the application shows to signed-in users (sidebar, mobile
 * header, browser tab): the name of the organisme's default company and its
 * quote logo, served by BrandLogoController (never inlined in the pages).
 * Without a default company (or a blank name): the application name; without
 * a logo: the generic icon (logoUrl null).
 *
 * Cached per organisme (key prefixed by the tenant id), dropped after any
 * write to a company (Company::booted), so a new name or logo shows at once.
 * The logo URL carries a version (start of the file's SHA-256): a new logo
 * gets a new URL, the browser cache of the old one is never reused.
 */
final class Brand
{
    public const TTL_SECONDS = 3600;

    public function __construct(private readonly TenantContext $tenants, private readonly CompanyBranding $branding) {}

    /**
     * @return array{name: string, logoUrl: string|null}
     */
    public function current(): array
    {
        /** @var array{name: string, logoUrl: string|null} */
        return Cache::remember(self::key($this->tenants->id()), self::TTL_SECONDS, fn () => $this->compute());
    }

    /**
     * The default company of the current organisme, if any.
     */
    public static function company(): ?Company
    {
        return Company::query()->where('is_default', true)->first();
    }

    /**
     * Drops the cached brand of an organisme once the current transaction
     * (if any) is committed: a reader never caches the state before the write.
     */
    public static function forget(string $tenantId): void
    {
        DB::afterCommit(fn () => Cache::forget(self::key($tenantId)));
    }

    public static function key(string $tenantId): string
    {
        return $tenantId.':brand';
    }

    /**
     * @return array{name: string, logoUrl: string|null}
     */
    private function compute(): array
    {
        $company = self::company();
        $name = trim((string) $company?->name);
        $logo = $company === null ? null : $this->branding->logoFile($company);

        return [
            'name' => $name !== '' ? $name : (string) config('app.name'),
            'logoUrl' => $logo === null ? null : route('branding.logo', ['v' => substr($logo['sha256'], 0, 16)], false),
        ];
    }
}
