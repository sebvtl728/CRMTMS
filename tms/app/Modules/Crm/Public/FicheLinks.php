<?php

namespace App\Modules\Crm\Public;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Support\LinkedData;
use Closure;

/**
 * What another module (Sessions…) keeps on the fiches of the CRM, declared
 * once so the CRM can honour it without knowing the module:
 *
 *  - linked: « something hangs on this fiche » (LinkedData): an import undo
 *    keeps the fiche;
 *  - merge: the module repoints its references from the absorbed fiche to
 *    the kept one, inside the merge transaction (it may refuse the merge
 *    with a validation error: nothing is then written);
 *  - anonymisation: the module clears the personal values it holds on a
 *    person (and the fiches merged into her), inside the anonymisation
 *    transaction;
 *  - export: the sections the module adds to the RGPD export of a person
 *    (complete, whatever the viewer's scopes), with the view rights that must
 *    reach « Tout l'organisme » for a user to download that export.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class FicheLinks
{
    /** @var array<string, Closure(string, string, string): void> */
    private array $merges = [];

    /** @var array<string, Closure(list<string>): void> */
    private array $anonymisations = [];

    /** @var array<string, Closure(string): array<string, mixed>> */
    private array $exports = [];

    /** @var array<string, list<string>> */
    private array $exportPermissions = [];

    /**
     * @param  string  $key  unique name (module.data); a second registration replaces the first
     * @param  Closure(string, string): bool  $hasLinked  (organisation|person, id of the fiche) => whether the module holds data on it
     */
    public static function registerLinked(string $key, Closure $hasLinked): void
    {
        LinkedData::register($key, fn (Organisation|Person $fiche, array $exceptIds): bool => $hasLinked(
            $fiche instanceof Organisation ? 'organisation' : 'person', (string) $fiche->getKey(),
        ));
    }

    /**
     * @param  Closure(string, string, string): void  $merge  (organisation|person, kept id, absorbed id)
     */
    public static function registerMerge(string $key, Closure $merge): void
    {
        self::registry()->merges[$key] = $merge;
    }

    /**
     * @param  Closure(list<string>): void  $anonymise  ids of the person and of the fiches merged into her
     */
    public static function registerAnonymisation(string $key, Closure $anonymise): void
    {
        self::registry()->anonymisations[$key] = $anonymise;
    }

    /**
     * @param  Closure(string): array<string, mixed>  $sections  id of the person => sections of the export (key => rows)
     * @param  list<string>  $viewPermissions  rights whose scope must be « Tout l'organisme » to download the export
     */
    public static function registerExport(string $key, Closure $sections, array $viewPermissions): void
    {
        self::registry()->exports[$key] = $sections;
        self::registry()->exportPermissions[$key] = $viewPermissions;
    }

    /**
     * Called by the Merger, in its transaction.
     *
     * @param  'organisation'|'person'  $type
     */
    public static function merge(string $type, string $keepId, string $dropId): void
    {
        foreach (self::registry()->merges as $merge) {
            $merge($type, $keepId, $dropId);
        }
    }

    /**
     * Called by the Anonymiser, in its transaction.
     *
     * @param  list<string>  $personIds
     */
    public static function anonymise(array $personIds): void
    {
        foreach (self::registry()->anonymisations as $anonymise) {
            $anonymise($personIds);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function export(string $personId): array
    {
        $sections = [];
        foreach (self::registry()->exports as $export) {
            $sections = [...$sections, ...$export($personId)];
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    public static function exportPermissions(): array
    {
        return array_values(array_unique(array_merge([], ...array_values(self::registry()->exportPermissions))));
    }

    private static function registry(): self
    {
        $app = app();
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self);
        }

        return $app->make(self::class);
    }
}
