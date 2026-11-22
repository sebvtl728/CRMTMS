<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IBAN (ISO 13616): country code, 2 check digits, then 11 to 30 letters or
 * digits; moved check digits first and letters turned into numbers (A = 10),
 * the number mod 97 must be 1. Expects the value without spaces, in capitals.
 */
class Iban implements ValidationRule
{
    public static function isValid(string $value): bool
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $value) !== 1) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($value, 4).substr($value, 0, 4)) as $char) {
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }
        // mod 97 piece by piece: the number is far beyond an integer.
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return $rest === 1;
    }

    /** « FR76 3000 6000 0112 3456 7890 189 »: groups of four, as printed. */
    public static function format(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('L\'IBAN est invalide : vérifiez sa clé et sa longueur.');
        }
    }
}
