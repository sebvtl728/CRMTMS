<?php

namespace App\Modules\Connectors\Smtp;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare hostname or IP address: no scheme, path, port, credentials or
 * spaces. Loopback / link-local / unspecified addresses (cloud metadata,
 * local services) are refused unless the install opts in
 * (tms.connectors.allow_internal_hosts, for a development mail catcher).
 */
final class SmtpHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? $value : '';

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHostname = (bool) preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host);

        if (! $isIp && ! $isHostname) {
            $fail('Indiquez uniquement le nom du serveur (ex. smtp.exemple.fr), sans protocole, port ni chemin.');

            return;
        }

        if ($isIp && self::isInternal($host) && ! config('tms.connectors.allow_internal_hosts')) {
            $fail('Cette adresse (locale ou réservée) n\'est pas autorisée comme serveur SMTP.');
        }
    }

    public static function isInternal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $normalised = (string) inet_ntop((string) inet_pton($ip));

        return $normalised === '::1' || $normalised === '::'
            || str_starts_with($normalised, '127.') || str_starts_with($normalised, '0.')
            || str_starts_with($normalised, '169.254.') || str_starts_with(strtolower($normalised), 'fe80:');
    }
}
