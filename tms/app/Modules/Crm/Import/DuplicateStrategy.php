<?php

namespace App\Modules\Crm\Import;

/**
 * What to do with a row that looks like an existing fiche (no source id match).
 *
 * - Skip: the row is ignored (the fiche is left as it is).
 * - Merge: a certain duplicate (same SIRET, e-mail or phone, or identical
 *   name in the same postal code / city) is completed with the file values
 *   for its empty fields; a possible duplicate (similar name) is ignored.
 * - Create: a new fiche is created anyway (impossible on an existing SIRET).
 */
enum DuplicateStrategy: string
{
    case Skip = 'skip';
    case Merge = 'merge';
    case Create = 'create';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Ignorer la ligne',
            self::Merge => 'Compléter la fiche existante',
            self::Create => 'Créer quand même',
        };
    }
}
