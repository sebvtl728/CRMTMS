<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Tenancy\Tenant;

/**
 * Absolute activation URL printed by the console commands (no request, so no
 * host to rely on): the given domain, else the organisme's first domain in
 * multi mode, else APP_URL.
 */
final class ActivationLink
{
    public static function url(string $plainToken, ?Tenant $tenant = null, ?string $domain = null): string
    {
        $domain = $domain !== null && $domain !== '' ? $domain : null;

        if ($domain === null && $tenant !== null && config('tms.mode') === 'multi') {
            $domain = $tenant->domains()->orderBy('host')->value('host');
        }

        $base = $domain !== null ? 'https://'.mb_strtolower(trim($domain)) : rtrim((string) config('app.url'), '/');

        return $base.route('activation.show', $plainToken, false);
    }
}
