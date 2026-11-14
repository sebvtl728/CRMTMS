<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantUrl;

/**
 * Absolute activation URL printed by the console commands (no request, so no
 * host to rely on): the given domain, else the organisme's first domain in
 * multi mode, else APP_URL.
 */
final class ActivationLink
{
    public static function url(string $plainToken, ?Tenant $tenant = null, ?string $domain = null): string
    {
        // Explicit organisme only (a console command has no current one).
        return ($tenant === null && ($domain === null || $domain === '') ? rtrim((string) config('app.url'), '/') : TenantUrl::root($tenant, $domain))
            .route('activation.show', $plainToken, false);
    }
}
