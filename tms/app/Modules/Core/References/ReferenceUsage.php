<?php

namespace App\Modules\Core\References;

use Illuminate\Support\Facades\DB;

/**
 * Registry of the columns that store the ID of a reference item. Business
 * modules register theirs from their service provider, e.g.
 *
 *     ReferenceUsage::register(ReferenceList::ContactOrigin, 'contacts', 'origin_id');
 *
 * An item referenced by any registered column (soft-deleted rows included, they
 * are still history) is « in use »: it can be deactivated but not deleted.
 * Items are matched by ID, never by code, so a rename never breaks a link.
 * The registry is empty in lot 1a: nothing references the lists yet.
 */
final class ReferenceUsage
{
    /** @var array<string, list<array{table: string, column: string}>> */
    private static array $registry = [];

    public static function register(ReferenceList $list, string $table, string $column): void
    {
        $entry = ['table' => $table, 'column' => $column];

        if (! in_array($entry, self::$registry[$list->value] ?? [], true)) {
            self::$registry[$list->value][] = $entry;
        }
    }

    public static function isInUse(ReferenceItem $item): bool
    {
        foreach (self::$registry[$item->list->value] ?? [] as $usage) {
            if (DB::table($usage['table'])->where($usage['column'], $item->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Empties the registry (tests).
     */
    public static function flush(): void
    {
        self::$registry = [];
    }
}
