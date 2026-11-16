<?php

namespace App\Modules\Sessions;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;

/**
 * Permissions of the Sessions module (lot 2a) and their default matrix,
 * registered into the Core catalogue by SessionsServiceProvider, one theme
 * of the « Rôles et droits » screen per domain. Creating is part of
 * « update »; the six rights carry a scope (own / das / all, AccessScope).
 */
final class Permissions
{
    /** Dossiers : contractant, lignes, financements, prix. */
    public const DOSSIERS_VIEW = 'sessions.dossiers.view';

    public const DOSSIERS_UPDATE = 'sessions.dossiers.update';

    public const DOSSIERS_DELETE = 'sessions.dossiers.delete';

    /** Sessions de formation et inscriptions des stagiaires. */
    public const SESSIONS_VIEW = 'sessions.sessions.view';

    public const SESSIONS_UPDATE = 'sessions.sessions.update';

    public const SESSIONS_DELETE = 'sessions.sessions.delete';

    /**
     * Themes of the « Rôles et droits » screen, in order: theme => permissions.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'Dossiers' => [self::DOSSIERS_VIEW, self::DOSSIERS_UPDATE, self::DOSSIERS_DELETE],
        'Sessions et inscriptions' => [self::SESSIONS_VIEW, self::SESSIONS_UPDATE, self::SESSIONS_DELETE],
    ];

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::DOSSIERS_VIEW => ['Consulter les dossiers', 'Voir les dossiers de formation, leurs lignes, leur prix et leurs financements.'],
            self::DOSSIERS_UPDATE => ['Créer et modifier les dossiers', 'Créer un dossier (depuis un devis accepté ou à la main), modifier ses lignes et ses financements, changer son état.'],
            self::DOSSIERS_DELETE => ['Supprimer des dossiers', 'Mettre des dossiers à la corbeille et les restaurer.'],
            self::SESSIONS_VIEW => ['Consulter les sessions', 'Voir les sessions de formation, leur calendrier et leurs inscrits.'],
            self::SESSIONS_UPDATE => ['Créer et modifier les sessions et inscriptions', 'Planifier et modifier les sessions, inscrire et désinscrire des stagiaires, enregistrer un abandon.'],
            self::SESSIONS_DELETE => ['Supprimer des sessions', 'Mettre des sessions à la corbeille et les restaurer.'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Every right of the module carries a scope.
     *
     * @return list<string>
     */
    public static function scoped(): array
    {
        return self::all();
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        $views = [self::DOSSIERS_VIEW, self::SESSIONS_VIEW];

        return match ($role) {
            Role::AdminOrganisme => self::all(),
            Role::Direction, Role::ResponsableDas => $views,
            Role::Commercial, Role::ChargeRelations, Role::Assistant => [...$views, self::DOSSIERS_UPDATE, self::SESSIONS_UPDATE],
        };
    }

    /**
     * Default scope of the scoped permissions (missing: the whole organisme).
     * The Responsable DAS works in the DAS he follows.
     *
     * @return array<string, AccessScope>
     */
    public static function defaultScopesFor(Role $role): array
    {
        return match ($role) {
            Role::ResponsableDas => array_fill_keys(self::scoped(), AccessScope::Das),
            default => [],
        };
    }
}
