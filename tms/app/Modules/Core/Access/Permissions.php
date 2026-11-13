<?php

namespace App\Modules\Core\Access;

/**
 * Permission catalogue: Core's administration permissions plus those the
 * modules register (Permissions::registerModule, e.g. crm.* from
 * CrmServiceProvider).
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
     * Permissions of the modules (crm.*, ...), registered by their service
     * provider so that Core never depends on a module.
     *
     * @var array<string, array{permissions: list<string>, defaults: callable(Role): list<string>}>
     */
    private static array $modules = [];

    /**
     * Adds a module's permissions to the catalogue and its default matrix.
     * Call from the module's ServiceProvider::register() (gates are defined in
     * AccessServiceProvider::boot()). Registering a module again replaces it.
     *
     * @param  list<string>  $permissions
     * @param  callable(Role): list<string>  $defaultsFor
     */
    public static function registerModule(string $module, array $permissions, callable $defaultsFor): void
    {
        self::$modules[$module] = ['permissions' => $permissions, 'defaults' => $defaultsFor];
    }

    /**
     * Core permissions, then each module's, in registration order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = self::core();

        foreach (self::$modules as $module) {
            array_push($all, ...$module['permissions']);
        }

        return array_values(array_unique($all));
    }

    /**
     * Default matrix: applied when a tenant's role is created, and offered
     * once to existing roles when it grows (PermissionSync).
     *
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        $defaults = match ($role) {
            Role::AdminOrganisme => self::core(),
            Role::Direction => [self::AUDIT_VIEW, self::EXPORTS_RUN],
            default => [],
        };

        foreach (self::$modules as $module) {
            array_push($defaults, ...($module['defaults'])($role));
        }

        return array_values(array_unique($defaults));
    }

    /**
     * @return list<string>
     */
    private static function core(): array
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
}
