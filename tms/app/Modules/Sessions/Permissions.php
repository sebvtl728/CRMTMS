<?php

namespace App\Modules\Sessions;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;

/**
 * Permissions of the Sessions module (lot 2a, planning 0.8.0) and their
 * default matrix, registered into the Core catalogue by
 * SessionsServiceProvider, one theme of the « Rôles et droits » screen per
 * domain. Creating is part of « update »; the rights on dossiers, sessions,
 * séances and attendance carry a scope (own / das / all, AccessScope), the
 * séances and their attendance also « Ses séances » (trainer: those of the
 * trainer linked to the user); trainers carry own / das / all too (own: created
 * by or linked to the user; das: attached to a DAS he follows or to none);
 * rooms are organisme-wide.
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

    /** Séances of the sessions (planning) and the attendance taken there (0.8.0). */
    public const SEANCES_VIEW = 'sessions.seances.view';

    public const SEANCES_MANAGE = 'sessions.seances.manage';

    public const ATTENDANCE_MANAGE = 'sessions.attendance.manage';

    public const ATTENDANCE_CLOSE = 'sessions.attendance.close';

    /** Trainers (CRM people declared trainers) and their unavailabilities. */
    public const TRAINERS_MANAGE = 'sessions.trainers.manage';

    /** Rooms and closure periods of the organisme (« Réglages » of the sessions). */
    public const ROOMS_MANAGE = 'sessions.rooms.manage';

    /**
     * Themes of the « Rôles et droits » screen, in order: theme => permissions.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'Dossiers' => [self::DOSSIERS_VIEW, self::DOSSIERS_UPDATE, self::DOSSIERS_DELETE],
        'Sessions et inscriptions' => [self::SESSIONS_VIEW, self::SESSIONS_UPDATE, self::SESSIONS_DELETE],
        'Planning et présences' => [self::SEANCES_VIEW, self::SEANCES_MANAGE, self::ATTENDANCE_MANAGE, self::ATTENDANCE_CLOSE],
        'Formateurs et salles' => [self::TRAINERS_MANAGE, self::ROOMS_MANAGE],
    ];

    /** Rights that may also carry « Ses séances » (AccessScope::Trainer). */
    public const TRAINER_SCOPED = [self::SEANCES_VIEW, self::ATTENDANCE_MANAGE];

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
            self::SEANCES_VIEW => ['Consulter les séances', 'Voir le planning des séances, leurs formateurs et leurs salles. « Ses séances » : celles dont il est le formateur, et leurs sessions.'],
            self::SEANCES_MANAGE => ['Planifier les séances', 'Créer, déplacer, annuler et supprimer des séances, générer les séances d\'un module.'],
            self::ATTENDANCE_MANAGE => ['Saisir les présences', 'Noter les présences des séances et les heures réalisées par mois. « Ses séances » : celles dont il est le formateur.'],
            self::ATTENDANCE_CLOSE => ['Clôturer les présences', 'Clôturer un mois de présences d\'une session et le rouvrir (avec un motif).'],
            self::TRAINERS_MANAGE => ['Gérer les formateurs', 'Déclarer des formateurs, leur statut, leurs DAS, leurs indisponibilités et leur compte. Un formateur sans DAS reste visible de tous.'],
            self::ROOMS_MANAGE => ['Gérer les salles et fermetures', 'Paramétrer les salles de formation et les périodes de fermeture de l\'organisme.'],
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
     * The rights of the module that carry a scope: all but rooms.
     *
     * @return list<string>
     */
    public static function scoped(): array
    {
        return array_values(array_diff(self::all(), [self::ROOMS_MANAGE]));
    }

    /**
     * Scopes offered on top of own / das / all: « Ses séances ».
     *
     * @return array<string, list<AccessScope>>
     */
    public static function extraScopes(): array
    {
        return array_fill_keys(self::TRAINER_SCOPED, [AccessScope::Trainer]);
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        $views = [self::DOSSIERS_VIEW, self::SESSIONS_VIEW];
        $planning = [self::SEANCES_VIEW, self::SEANCES_MANAGE, self::ATTENDANCE_MANAGE, self::ATTENDANCE_CLOSE, self::TRAINERS_MANAGE, self::ROOMS_MANAGE];

        return match ($role) {
            Role::AdminOrganisme => self::all(),
            Role::Direction => [...$views, ...$planning],
            // He sees the sessions of his DAS: their séances too.
            Role::ResponsableDas => [...$views, self::SEANCES_VIEW],
            // Who manages the sessions plans them (closing a month: direction and administrator).
            Role::Commercial, Role::ChargeRelations, Role::Assistant => [...$views, self::DOSSIERS_UPDATE, self::SESSIONS_UPDATE, ...array_values(array_diff($planning, [self::ATTENDANCE_CLOSE]))],
            Role::Formateur => self::TRAINER_SCOPED,
        };
    }

    /**
     * Default scope of the scoped permissions (missing: the whole organisme).
     * The Responsable DAS works in the DAS he follows, the Formateur on his
     * own séances.
     *
     * @return array<string, AccessScope>
     */
    public static function defaultScopesFor(Role $role): array
    {
        return match ($role) {
            Role::ResponsableDas => array_fill_keys(self::scoped(), AccessScope::Das),
            Role::Formateur => array_fill_keys(self::TRAINER_SCOPED, AccessScope::Trainer),
            default => [],
        };
    }
}
