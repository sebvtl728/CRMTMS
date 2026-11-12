<?php

namespace App\Modules\Connectors\Smtp;

use Closure;

/**
 * Network policy for SMTP hosts, applied at save (SmtpHost rule) and again
 * at send time (SmtpTransportFactory::make).
 *
 *  - "internal" addresses (loopback, link-local incl. the 169.254.169.254
 *    cloud metadata endpoint, unspecified, multicast / reserved, shared
 *    address space, and the IPv4-embedded forms of all of them) are always
 *    refused unless tms.connectors.allow_internal_hosts;
 *  - "private" addresses (RFC 1918, IPv6 ULA) are refused unless
 *    tms.connectors.allow_private_hosts (default: allowed in single mode,
 *    refused in multi mode).
 *
 * Residual risk (documented): DNS rebinding between the check and the connection.
 */
final class SmtpHostPolicy
{
    /** @var (Closure(string): list<string>)|null test seam */
    private static ?Closure $resolver = null;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * IP addresses of $host (the host itself when it is a literal).
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * French reason why $ips are not allowed, or null.
     *
     * @param  list<string>  $ips
     */
    public static function violation(array $ips): ?string
    {
        foreach ($ips as $ip) {
            $class = self::classify($ip);
            if ($class === 'internal' && ! config('tms.connectors.allow_internal_hosts')) {
                return 'une adresse locale ou réservée';
            }
            if ($class === 'private' && ! config('tms.connectors.allow_private_hosts')) {
                return 'une adresse de réseau privé';
            }
        }

        return null;
    }

    /**
     * @return 'internal'|'private'|null
     */
    public static function classify(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'internal'; // not an address we understand: refuse
        }

        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d), NAT64 (64:ff9b::/96): judge the embedded IPv4.
            $prefix = substr($bin, 0, 12);
            if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === hex2bin('0064ff9b0000000000000000')
                || (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) {
                return self::classifyV4(substr($bin, 12)) ?? null;
            }
            if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15)."\1") {
                return 'internal';
            }
            $first = ord($bin[0]);
            $second = ord($bin[1]);

            return match (true) {
                $first === 0xFF => 'internal',                         // multicast
                $first === 0xFE && ($second & 0xC0) === 0x80 => 'internal', // fe80::/10 link-local
                ($first & 0xFE) === 0xFC => 'private',                 // fc00::/7 unique local
                default => null,
            };
        }

        return self::classifyV4($bin);
    }

    /**
     * @return 'internal'|'private'|null
     */
    private static function classifyV4(string $bin): ?string
    {
        [$a, $b] = [ord($bin[0]), ord($bin[1])];

        return match (true) {
            $a === 0, $a === 127, $a >= 224 => 'internal',                  // this-network, loopback, multicast, reserved
            $a === 169 && $b === 254 => 'internal',                         // link-local, cloud metadata
            $a === 100 && ($b & 0xC0) === 64 => 'internal',                 // 100.64.0.0/10 shared address space
            $a === 192 && $b === 0 && ord($bin[2]) === 0 => 'internal',     // 192.0.0.0/24
            $a === 10, $a === 172 && ($b & 0xF0) === 16, $a === 192 && $b === 168 => 'private',
            default => null,
        };
    }
}
