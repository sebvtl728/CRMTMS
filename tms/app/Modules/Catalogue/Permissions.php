<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Access\Role;

/**
 * Permissions of the Catalogue module and their default matrix, registered
 * into the Core catalogue by CatalogueServiceProvider. Reading is granted to
 * every role that works in the CRM; changing and deleting trainings to the
 * administrator and the direction.
 */
final class Permissions
{
    public const VIEW = 'catalogue.view';

    public const MANAGE = 'catalogue.manage';

    public const DELETE = 'catalogue.delete';

    /** The Excel export follows the CRM exports right. */
    public const EXPORT = 'crm.export';

    /** Theme on the « Rôles et droits » screen. */
    public const GROUP = 'Catalogue';

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter le catalogue', 'Voir les formations, leurs tarifs et financements, et télécharger les plaquettes.'],
            self::MANAGE => ['Modifier le catalogue', 'Créer, modifier, désactiver ou importer des formations ; déposer et modifier des plaquettes.'],
            self::DELETE => ['Supprimer des formations', "Supprimer une formation du catalogue, quand rien ne l'utilise ; mettre une plaquette à la corbeille."],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE, self::DELETE];
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme, Role::Direction => [self::VIEW, self::MANAGE, self::DELETE],
            Role::Commercial, Role::ChargeRelations, Role::Assistant, Role::ResponsableDas => [self::VIEW],
        };
    }
}
