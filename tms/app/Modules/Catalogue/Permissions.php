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
