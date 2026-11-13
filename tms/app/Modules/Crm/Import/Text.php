<?php

namespace App\Modules\Crm\Import;

use Illuminate\Support\Str;

final class Text
{
    /**
     * Comparison key: no accents, lower case, punctuation as spaces, single
     * spaces (« Tél. » = « tel », « E-mail » = « e mail »).
     */
    public static function key(string $value): string
    {
        $ascii = mb_strtolower(Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii);
    }
}
