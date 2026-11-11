<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * French SIRET: 14 digits passing the Luhn check.
 *
 * Known limit: La Poste establishments (SIREN 356 000 000) do not follow Luhn
 * (their digit sum must be a multiple of 5). They are not supported here.
 */
class Siret implements ValidationRule
{
    public static function isValid(string $value): bool
    {
        if (preg_match('/^\d{14}\z/', $value) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (str_split(strrev($value)) as $i => $char) {
            $digit = (int) $char;
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('Le numéro SIRET est invalide (14 chiffres, clé de contrôle incorrecte).');
        }
    }
}
