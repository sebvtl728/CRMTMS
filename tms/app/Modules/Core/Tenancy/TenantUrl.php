<?php

namespace App\Modules\Core\Tenancy;

/**
 * The organisme's address, for links built without a request (queued jobs,
 * scheduled commands, mails): the given domain, else the organisme's first
 * domain in multi mode (the current one by default), else APP_URL. Never
 * the worker's host.
 */
final class TenantUrl
{
    /** Scheme and host, without trailing slash. */
    public static function root(?Tenant $tenant = null, ?string $domain = null): string
    {
        $domain = $domain !== null && trim($domain) !== '' ? $domain : null;
        $tenant ??= app(TenantContext::class)->current();

        if ($domain === null && $tenant !== null && config('tms.mode') === 'multi') {
            $domain = $tenant->domains()->orderBy('host')->value('host');
        }

        return is_string($domain) ? 'https://'.mb_strtolower(trim($domain)) : rtrim((string) config('app.url'), '/');
    }

    /**
     * Absolute URL of a named route on the organisme's address.
     *
     * @param  array<string, mixed>|string  $parameters
     */
    public static function route(string $name, array|string $parameters = [], ?Tenant $tenant = null): string
    {
        return self::root($tenant).route($name, $parameters, false);
    }
}
