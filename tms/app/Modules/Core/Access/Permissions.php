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

    /** « Rôles et droits » screen: the administrator only, never editable. */
    public const ROLES_MANAGE = 'roles.manage';

    /**
     * Never granted to another role from the « Rôles et droits » screen
     * (the administrator always holds them): whoever manages users or rights
     * could otherwise give himself everything, and whoever manages the
     * connectors could point the organisme's SMTP at his own relay and
     * capture the next invitation link.
     */
    public const LOCKED = [self::USERS_MANAGE, self::ROLES_MANAGE, self::CONNECTORS_MANAGE];

    /** @var array<string, string> permission => warning shown on the « Rôles et droits » screen */
    public const WARNINGS = [
        self::EXPORTS_RUN => "Droit de niveau administrateur : les exports système portent sur tout l'organisme, sans tenir compte des DAS suivis.",
        self::SETTINGS_MANAGE => "Droit de niveau administrateur : sécurité des sessions, textes des e-mails envoyés au nom de l'organisme et durée de conservation RGPD.",
    ];

    /** Theme of the Core permissions on the « Rôles et droits » screen. */
    public const CORE_GROUP = 'Administration';

    /** @var array<string, array{0: string, 1: string}> permission => [French label, one-sentence explanation] */
    private const CORE_LABELS = [
        self::USERS_MANAGE => ['Gérer les utilisateurs', 'Inviter, modifier, désactiver les comptes et changer leur rôle.'],
        self::ROLES_MANAGE => ['Gérer les rôles et droits', 'Modifier les droits de chaque rôle sur cet écran.'],
        self::COMPANIES_MANAGE => ['Gérer les sociétés', 'Créer et modifier les sociétés émettrices des devis (mentions légales, logo, numérotation).'],
        self::REFERENCES_MANAGE => ['Gérer les référentiels', 'Modifier les listes paramétrables (DAS, étapes, origines…).'],
        self::CONNECTORS_MANAGE => ['Gérer les connecteurs', 'Configurer les logiciels externes (messagerie, annuaire des entreprises, agenda…).'],
        self::AUDIT_VIEW => ["Consulter le journal d'audit", "Voir qui a fait quoi et quand dans l'organisme."],
        self::SETTINGS_MANAGE => ['Gérer les paramètres', "Régler la sécurité de l'organisme (déconnexion après inactivité…), les modèles d'e-mails (dont le texte de l'invitation) et la durée de conservation RGPD."],
        self::EXPORTS_RUN => ['Lancer les exports système', "Exports techniques de l'organisme (hors exports Excel des listes)."],
    ];

    /**
     * Permissions of the modules (crm.*, ...), registered by their service
     * provider so that Core never depends on a module.
     *
     * @var array<string, array{permissions: list<string>, defaults: callable(Role): list<string>, labels: array<string, array{0: string, 1: string}>, group: string}>
     */
    private static array $modules = [];

    /**
     * Adds a module's permissions to the catalogue and its default matrix.
     * Call from the module's ServiceProvider::register() (gates are defined in
     * AccessServiceProvider::boot()). Registering a module again replaces it.
     *
     * Every permission comes with its French label and explanation (the
     * « Rôles et droits » screen; a test fails for one without), under $group.
     *
     * @param  list<string>  $permissions
     * @param  callable(Role): list<string>  $defaultsFor
     * @param  array<string, array{0: string, 1: string}>  $labels  permission => [label, explanation]
     */
    public static function registerModule(string $module, array $permissions, callable $defaultsFor, array $labels = [], string $group = ''): void
    {
        self::$modules[$module] = ['permissions' => $permissions, 'defaults' => $defaultsFor, 'labels' => $labels, 'group' => $group];
    }

    /**
     * French label, explanation and theme of a permission; null when its
     * module gave none.
     *
     * @return array{label: string, description: string, group: string}|null
     */
    public static function label(string $permission): ?array
    {
        if (isset(self::CORE_LABELS[$permission])) {
            return ['label' => self::CORE_LABELS[$permission][0], 'description' => self::CORE_LABELS[$permission][1], 'group' => self::CORE_GROUP];
        }
        foreach (self::$modules as $module) {
            if (in_array($permission, $module['permissions'], true) && isset($module['labels'][$permission])) {
                return ['label' => $module['labels'][$permission][0], 'description' => $module['labels'][$permission][1], 'group' => $module['group']];
            }
        }

        return null;
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
            self::ROLES_MANAGE,
            self::COMPANIES_MANAGE,
            self::REFERENCES_MANAGE,
            self::CONNECTORS_MANAGE,
            self::AUDIT_VIEW,
            self::SETTINGS_MANAGE,
            self::EXPORTS_RUN,
        ];
    }
}
