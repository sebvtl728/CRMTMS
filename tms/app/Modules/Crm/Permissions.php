<?php

namespace App\Modules\Crm;

use App\Modules\Core\Access\Role;

/**
 * Permissions of the CRM module (organisations, people, import, GDPR) and
 * their default matrix, registered into the Core catalogue by
 * CrmServiceProvider.
 */
final class Permissions
{
    public const VIEW = 'crm.view';

    public const CREATE = 'crm.create';

    public const UPDATE = 'crm.update';

    public const DELETE = 'crm.delete';

    public const MERGE = 'crm.merge';

    public const IMPORT = 'crm.import';

    public const EXPORT = 'crm.export';

    public const GDPR = 'crm.gdpr';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::CREATE, self::UPDATE, self::DELETE, self::MERGE, self::IMPORT, self::EXPORT, self::GDPR];
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme => self::all(),
            Role::Direction => [self::VIEW, self::EXPORT, self::GDPR],
            Role::Commercial, Role::ChargeRelations => [self::VIEW, self::CREATE, self::UPDATE, self::DELETE, self::MERGE, self::IMPORT, self::EXPORT],
            Role::Assistant => [self::VIEW, self::CREATE, self::UPDATE],
            Role::ResponsableDas => [self::VIEW, self::EXPORT],
        };
    }
}
