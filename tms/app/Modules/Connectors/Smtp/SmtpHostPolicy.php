<?php

namespace App\Modules\Connectors\Smtp;

use App\Modules\Connectors\Core\HostPolicy;
use Closure;

/**
 * SMTP flavour of the generic HostPolicy (same rules, same resolver seam),
 * kept so SMTP callers and tests are unchanged.
 */
final class SmtpHostPolicy
{
    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        HostPolicy::resolveUsing($resolver);
    }

    /**
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        return HostPolicy::resolve($host);
    }

    /**
     * @param  list<string>  $ips
     */
    public static function violation(array $ips): ?string
    {
        return HostPolicy::violation($ips);
    }

    /**
     * @return 'internal'|'private'|null
     */
    public static function classify(string $ip): ?string
    {
        return HostPolicy::classify($ip);
    }
}
