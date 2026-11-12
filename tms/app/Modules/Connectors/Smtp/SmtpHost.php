<?php

namespace App\Modules\Connectors\Smtp;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare hostname or IP address: no scheme, path, port, credentials, spaces
 * or numeric shorthand (127.1, 2130706433). The addresses it resolves to
 * must satisfy SmtpHostPolicy. A name that does not resolve yet is accepted
 * here (the connection test reports it; send time re-checks everything).
 */
final class SmtpHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? $value : '';

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHostname = (bool) preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host)
            // A final all-numeric label is an IPv4 shorthand (inet_aton), not a name.
            && ! preg_match('/(^|\.)[0-9]+$/', $host);

        if (! $isIp && ! $isHostname) {
            $fail('Indiquez uniquement le nom du serveur (ex. smtp.exemple.fr), sans protocole, port ni chemin.');

            return;
        }

        if (($reason = SmtpHostPolicy::violation(SmtpHostPolicy::resolve($host))) !== null) {
            $fail("Ce serveur pointe vers {$reason} : adresse non autorisée.");
        }
    }
}
