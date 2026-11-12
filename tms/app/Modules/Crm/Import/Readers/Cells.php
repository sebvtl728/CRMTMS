<?php

namespace App\Modules\Crm\Import\Readers;

final class Cells
{
    /**
     * Trimmed text, including non-breaking spaces (Excel exports them).
     */
    public static function clean(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}\x{202F}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{FEFF}]+$/u', '', $value) ?? trim($value);
    }
}
