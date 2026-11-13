<?php

namespace App\Modules\Connectors\Sirene;

use App\Modules\Connectors\Core\HostPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Base URL of the directory API: https, a bare host (no credentials, port,
 * path or query) that resolves only to public addresses. Unlike SMTP, no
 * configuration switch ever allows a local or private host here. A name that
 * does not resolve yet is accepted (the call re-checks everything).
 */
final class SireneBaseUrl implements ValidationRule
{
    public static function host(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $host = $parts['host'];
        $isIp = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        $isName = (bool) preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host)
            && ! preg_match('/(^|\.)[0-9]+$/', $host);

        return $isIp || $isName ? trim($host, '[]') : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? self::host($value) : null;
        if ($host === null) {
            $fail('Indiquez une adresse https:// sans identifiant, port ni chemin (ex. https://recherche-entreprises.api.gouv.fr).');

            return;
        }

        if (($reason = HostPolicy::violation(HostPolicy::resolve($host), strict: true)) !== null) {
            $fail("Cet hôte pointe vers {$reason} : adresse non autorisée.");
        }
    }
}
