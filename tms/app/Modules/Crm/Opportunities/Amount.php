<?php

namespace App\Modules\Crm\Opportunities;

/**
 * An amount typed the French way (« 12 500,00 € ») read as a decimal
 * string (« 12500.00 »); anything else is returned unchanged for the
 * validation to refuse.
 */
final class Amount
{
    public static function parse(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/[\s\x{00A0}\x{202F}€]/u', '', $value) ?? $value;
        if ($clean === '') {
            return null;
        }

        return preg_match('/^-?\d+(,\d+)?$/', $clean) === 1 ? str_replace(',', '.', $clean) : $clean;
    }
}
