<?php

namespace App\Modules\Crm\Import;

/**
 * Recognises the shape of one cell (e-mail, French mobile or landline,
 * postcode, SIRET or SIREN with their Luhn key, date, website, civility), so
 * the columns step can propose a field from what a column contains. Pure
 * function: nothing is kept.
 */
final class ValueShapes
{
    public const EMAIL = 'email';

    public const MOBILE = 'mobile';

    public const PHONE = 'phone';

    public const POSTCODE = 'postcode';

    public const SIRET = 'siret';

    public const SIREN = 'siren';

    public const DATE = 'date';

    public const WEBSITE = 'website';

    public const CIVILITY = 'civility';

    /** Comparison keys (Text::key) of the usual civilities. */
    private const CIVILITIES = ['m', 'mr', 'mme', 'mlle', 'mm', 'monsieur', 'madame', 'mademoiselle', 'messieurs', 'mesdames', 'm et mme', 'dr', 'docteur', 'me', 'maitre'];

    /** Top-level domains accepted for a bare domain name (« exemple.fr »). */
    private const DOMAINS = ['fr', 'com', 'net', 'org', 'eu', 'info', 'biz', 'io', 'be', 'ch', 'lu', 'ca', 're', 'gp', 'mq', 'yt', 'nc', 'pf', 'coop', 'asso', 'bzh', 'paris', 'alsace', 'corsica', 'co', 'pro', 'tech', 'online', 'site'];

    public static function of(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '@')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? self::EMAIL : null;
        }
        if (($digits = self::digits($value)) !== null) {
            return self::number($digits);
        }
        if (($phone = self::phoneDigits($value)) !== null) {
            return self::phone($phone);
        }
        if (self::isDate($value)) {
            return self::DATE;
        }
        if (self::isWebsite($value)) {
            return self::WEBSITE;
        }
        if (in_array(Text::key($value), self::CIVILITIES, true)) {
            return self::CIVILITY;
        }

        return null;
    }

    /** Only digits and spaces: the digits, else null. */
    private static function digits(string $value): ?string
    {
        if (preg_match('/^[0-9 \x{00A0}]+$/u', $value) !== 1) {
            return null;
        }

        return preg_replace('/\D/', '', $value) ?? '';
    }

    private static function number(string $digits): ?string
    {
        return match (strlen($digits)) {
            5 => self::POSTCODE,
            9 => self::luhn($digits) ? self::SIREN : null,
            14 => self::isSiret($digits) ? self::SIRET : null,
            10 => self::phone($digits),
            default => null,
        };
    }

    /**
     * A phone number written with separators or an international prefix: its
     * national digits (« 0612345678 »), else null.
     */
    private static function phoneDigits(string $value): ?string
    {
        if (preg_match('/^\+?[0-9 .\-()\x{00A0}]+$/u', $value) !== 1) {
            return null;
        }
        $compact = preg_replace('/[^0-9+]/', '', str_replace('(0)', '', $value)) ?? '';
        if (str_starts_with($compact, '+33')) {
            $compact = '0'.substr($compact, 3);
        } elseif (str_starts_with($compact, '0033')) {
            $compact = '0'.substr($compact, 4);
        }

        return preg_match('/^0\d{9}$/', $compact) === 1 ? $compact : null;
    }

    private static function phone(string $digits): ?string
    {
        if (preg_match('/^0[67]\d{8}$/', $digits) === 1) {
            return self::MOBILE;
        }

        return preg_match('/^0[1-589]\d{8}$/', $digits) === 1 ? self::PHONE : null;
    }

    /** 14 digits, Luhn key (La Poste establishments: sum of the digits multiple of 5). */
    private static function isSiret(string $digits): bool
    {
        if (str_starts_with($digits, '356000000')) {
            return array_sum(str_split($digits)) % 5 === 0;
        }

        return self::luhn($digits);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /** 16/11/2026, 16-11-26, 16.11.2026 or 2026-11-16, optionally followed by a time. */
    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }

        return false;
    }

    /** An address with a scheme or « www. », or a bare domain under a usual extension. */
    private static function isWebsite(string $value): bool
    {
        $value = mb_strtolower($value);
        if (preg_match('/\s/', $value) === 1) {
            return false;
        }
        $explicit = preg_match('#^(https?://|www\.)#', $value) === 1;
        $host = preg_replace('#^(https?://)?(www\.)?#', '', $value) ?? '';
        $host = explode('/', $host, 2)[0];
        if (preg_match('/^([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+([a-z]{2,24})$/', $host, $m) !== 1) {
            return false;
        }

        return $explicit || in_array($m[3], self::DOMAINS, true);
    }
}
