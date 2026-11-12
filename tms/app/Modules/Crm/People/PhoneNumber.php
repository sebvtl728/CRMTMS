<?php

namespace App\Modules\Crm\People;

/**
 * Phone numbers are stored in E.164 when they can be recognised
 * (0612345678 -> +33612345678); anything else is kept as typed.
 */
final class PhoneNumber
{
    public static function normalise(string $input): ?string
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        // Only digits and usual separators: otherwise (« poste 12 ») keep the typing.
        if (preg_match('/^\+?[\d\s.\-()\/]+$/', $input) !== 1) {
            return $input;
        }

        $plus = str_starts_with($input, '+');
        // « +33 (0)6 12... »: the national trunk zero in brackets is dropped.
        $digits = preg_replace('/\D/', '', preg_replace('/\(0\)/', '', $input) ?? $input) ?? '';

        if (! $plus && str_starts_with($digits, '00')) {
            $plus = true;
            $digits = substr($digits, 2);
        }

        if ($plus) {
            if (str_starts_with($digits, '33') && preg_match('/^33[1-9]\d{8}$/', $digits) === 1) {
                return '+'.$digits;
            }

            return preg_match('/^\d{8,15}$/', $digits) === 1 && ! str_starts_with($digits, '0') && ! str_starts_with($digits, '33') ? '+'.$digits : $input;
        }

        return preg_match('/^0[1-9]\d{8}$/', $digits) === 1 ? '+33'.substr($digits, 1) : $input;
    }
}
