<?php

namespace App\Modules\Sessions\Sessions;

/**
 * The colour of a session (2026-11-22): given in turn from a fixed palette of
 * twelve distinct colours when the session is saved for the first time, so
 * two sessions created one after the other never share it; the séances of
 * the planning are painted with it.
 */
final class SessionColors
{
    /** Distinct, readable on white and on dark backgrounds (the planning uses a light tint and a solid edge). */
    public const PALETTE = [
        '#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2',
        '#ca8a04', '#db2777', '#4f46e5', '#059669', '#b45309', '#475569',
    ];

    /** The colour after the one of the last session created (the first of the palette for the first one). */
    public static function next(): string
    {
        $last = TrainingSession::withTrashed()->whereNotNull('color')->orderByDesc('created_at')->orderByDesc('id')->value('color');
        $index = $last === null ? -1 : array_search($last, self::PALETTE, true);

        return self::PALETTE[((is_int($index) ? $index : -1) + 1) % count(self::PALETTE)];
    }
}
