<?php

namespace App\Modules\Core\Access;

/**
 * Permission catalogue. Lot 1a only has administration permissions; later
 * lots add theirs (crm.*) here and in the default matrix.
 */
final class Permissions
{
    public const USERS_MANAGE = 'users.manage';

    public const COMPANIES_MANAGE = 'companies.manage';

    public const REFERENCES_MANAGE = 'references.manage';

    public const CONNECTORS_MANAGE = 'connectors.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const EXPORTS_RUN = 'exports.run';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USERS_MANAGE,
            self::COMPANIES_MANAGE,
            self::REFERENCES_MANAGE,
            self::CONNECTORS_MANAGE,
            self::AUDIT_VIEW,
            self::SETTINGS_MANAGE,
            self::EXPORTS_RUN,
        ];
    }

    /**
     * Default matrix, applied when a tenant's role is created (RoleSeeder).
     *
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme => self::all(),
            Role::Direction => [self::AUDIT_VIEW, self::EXPORTS_RUN],
            default => [],
        };
    }
}
