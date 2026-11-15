<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Access\Role;

/**
 * Permissions of the Catalogue module and their default matrix, registered
 * into the Core catalogue by CatalogueServiceProvider. Reading is granted to
 * every role that works in the CRM (all of them hold crm.view); managing the
 * catalogue to the administrator and the direction.
 */
final class Permissions
{
    public const VIEW = 'catalogue.view';

    public const MANAGE = 'catalogue.manage';

    /** The Excel export follows the CRM exports right. */
    public const EXPORT = 'crm.export';

    /** Theme on the « Rôles et droits » screen. */
    public const GROUP = 'Catalogue de formations';

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter le catalogue', 'Voir les formations, leurs tarifs et financements.'],
            self::MANAGE => ['Gérer le catalogue', 'Créer, modifier, désactiver, importer ou supprimer des formations.'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE];
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme, Role::Direction => [self::VIEW, self::MANAGE],
            Role::Commercial, Role::ChargeRelations, Role::Assistant, Role::ResponsableDas => [self::VIEW],
        };
    }
}
