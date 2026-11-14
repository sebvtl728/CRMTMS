<?php

use App\Modules\Core\Tenancy\MissingTenantContext;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;

it('storage path is prefixed by tenant and refuses without context', function () {
    $tenant = inTenant();

    expect(TenantStorage::path('imports'))->toBe("tenants/{$tenant->id}/imports")
        ->and(TenantStorage::path('attachments', 'a/b.pdf'))->toBe("tenants/{$tenant->id}/attachments/a/b.pdf")
        ->and(TenantStorage::path('exports', '/x.csv'))->toBe("tenants/{$tenant->id}/exports/x.csv")
        ->and(TenantStorage::path('gdpr', 'r.zip'))->toBe("tenants/{$tenant->id}/gdpr/r.zip")
        ->and(TenantStorage::path('quotes', 'q/CF-2026-0001.pdf'))->toBe("tenants/{$tenant->id}/quotes/q/CF-2026-0001.pdf")
        ->and(TenantStorage::DISK)->toBe('local');

    app(TenantContext::class)->clear();

    expect(fn () => TenantStorage::path('imports'))->toThrow(MissingTenantContext::class);
});

it('refuses an unknown area', function () {
    inTenant();

    TenantStorage::path('secrets');
})->throws(InvalidArgumentException::class);

it('refuses a relative path escaping the tenant directory', function (string $relative) {
    inTenant();

    TenantStorage::path('imports', $relative);
})->with(['../other', 'a/../../b', '..', "a\0b"])->throws(InvalidArgumentException::class);
