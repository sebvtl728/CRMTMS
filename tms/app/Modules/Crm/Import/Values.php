<?php

namespace App\Modules\Crm\Import;

use App\Rules\Siret;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parsing of the text cells of a file into stored values. Each parser
 * returns ['value' => ..., 'error' => French message|null, 'warning' => ...].
 */
final class Values
{
    public const SIRET_ALTERED = 'SIRET altéré par Excel (notation scientifique) : mettez la colonne au format Texte dans le fichier d\'origine puis réimportez la ligne.';

    public const SIRET_INVALID = 'Le numéro SIRET est invalide (14 chiffres, clé de contrôle incorrecte).';

    public const SIRET_PADDED = 'SIRET : zéros initiaux rétablis (supprimés par Excel).';

    /**
     * A SIRET as Excel may have damaged it:
     * - « 7,32829E+13 » / « 7.32829E+13 » or a decimal: digits lost, refused;
     * - 14 digits failing the check with 3+ trailing zeros: digits lost by a
     *   scientific notation (73282900000000), refused with the same message;
     * - 12 or 13 digits: leading zeros removed by a number format, restored
     *   (zeros do not change the Luhn key, which is still checked). A 9-digit
     *   SIREN is never padded.
     *
     * @return array{value: string|null, error: string|null, warning: string|null}
     */
    public static function siret(string $raw): array
    {
        $value = preg_replace('/[\s.\x{00A0}\x{202F}]/u', '', $raw) ?? $raw;

        if (preg_match('/^\d+(?:[.,]\d+)?E[+-]?\d+$/i', str_replace(' ', '', $raw)) === 1
            || preg_match('/^\d+(?:[.,]\d+)?E[+-]?\d+$/i', $value) === 1
            || preg_match('/^\d{4,}[.,]\d+$/', trim($raw)) === 1) {
            return ['value' => null, 'error' => self::SIRET_ALTERED, 'warning' => null];
        }

        if (preg_match('/^\d{12,13}$/', $value) === 1 && Siret::isValid(str_pad($value, 14, '0', STR_PAD_LEFT))) {
            return ['value' => str_pad($value, 14, '0', STR_PAD_LEFT), 'error' => null, 'warning' => self::SIRET_PADDED];
        }

        if (Siret::isValid($value)) {
            return ['value' => $value, 'error' => null, 'warning' => null];
        }

        if (preg_match('/^\d{11,14}$/', $value) === 1 && preg_match('/000$/', $value) === 1) {
            return ['value' => null, 'error' => self::SIRET_ALTERED, 'warning' => null];
        }

        return ['value' => null, 'error' => self::SIRET_INVALID, 'warning' => null];
    }

    /**
     * JJ/MM/AAAA (also with - or .), AAAA-MM-JJ, optionally followed by HH:MM[:SS].
     */
    public static function date(string $raw, bool $withTime = false): ?CarbonImmutable
    {
        $raw = trim($raw);
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(?:[ T](\d{1,2})[:h](\d{2})(?::(\d{2}))?)?$#', $raw, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $raw, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        try {
            $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, config('app.timezone'));
            if ($date !== null && $withTime && isset($m[4]) && $m[4] !== '') {
                $date = $date->setTime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0));
            }

            return $date ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function bool(string $raw): ?bool
    {
        return match (Text::key($raw)) {
            'oui', 'o', 'yes', 'y', '1', 'x', 'vrai', 'true' => true,
            'non', 'n', 'no', '0', 'faux', 'false' => false,
            default => null,
        };
    }

    public static function civility(string $raw): ?string
    {
        return match (Text::key($raw)) {
            'm', 'mr', 'monsieur', 'mon' => 'M.',
            'mme', 'madame', 'mlle', 'mademoiselle', 'mle' => 'Mme',
            default => null,
        };
    }

    public static function country(string $raw): string
    {
        return match (Text::key($raw)) {
            'france', 'fr', 'fra' => 'FR',
            'belgique' => 'BE', 'suisse' => 'CH', 'luxembourg' => 'LU', 'allemagne' => 'DE', 'espagne' => 'ES', 'italie' => 'IT',
            default => mb_strtoupper(trim($raw)),
        };
    }
}
