<?php

namespace App\Modules\Core\Audit;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Stable names for the models stored in polymorphic columns (audit_logs,
 * notes, role assignments...): a class can then be renamed or moved without
 * touching data. Enforced: an unmapped model in a morph relation throws.
 * Each module registers its own aliases from its service provider.
 */
final class MorphAliases
{
    /**
     * @param  array<string, class-string>  $aliases  alias => model class
     */
    public static function register(array $aliases): void
    {
        Relation::enforceMorphMap($aliases);
    }
}
