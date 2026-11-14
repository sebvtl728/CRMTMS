<?php

namespace App\Modules\Crm\Support;

/**
 * Reads a list filter from untrusted query input: anything that is not the
 * expected scalar shape (arrays, junk) becomes « no filter ».
 */
final class FilterInput
{
    public static function text(mixed $value, int $max = 100): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    public static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    public static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
