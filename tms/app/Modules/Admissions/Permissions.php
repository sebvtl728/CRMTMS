<?php

namespace App\Modules\Admissions;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;

/**
 * Permissions of the Admissions module and their default matrix, registered
 * into the Core catalogue by AdmissionsServiceProvider. Creating is part of
 * « update »; the candidature rights carry a scope (own: responsible or
 * creator; das: the DAS of the candidature). The sensitive data (RQTH,
 * adaptation needs, interview report) need their own right, organisme-wide.
 */
final class Permissions
{
    public const CANDIDATURES_VIEW = 'admissions.candidatures.view';

    public const CANDIDATURES_UPDATE = 'admissions.candidatures.update';

    public const CANDIDATURES_DELETE = 'admissions.candidatures.delete';

    public const SENSITIVE_VIEW = 'admissions.sensitive.view';

    /** Follow the apprenticeship booklet (grids, visits, monthly pages, links); « Ses séances » for the trainers. */
    public const BOOKLET_MANAGE = 'admissions.booklet.manage';

    /**
     * Themes of the « Rôles et droits » screen, in order: theme => permissions.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'Candidatures' => [self::CANDIDATURES_VIEW, self::CANDIDATURES_UPDATE, self::CANDIDATURES_DELETE],
        'Données sensibles des candidats' => [self::SENSITIVE_VIEW],
        'Livret d\'apprentissage' => [self::BOOKLET_MANAGE],
    ];

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::CANDIDATURES_VIEW => ['Consulter les candidatures', 'Voir les candidats, apprenants et alternants, leur parcours d\'admission et leur contrat.'],
            self::CANDIDATURES_UPDATE => ['Créer et modifier les candidatures', 'Ouvrir une candidature, saisir le positionnement, l\'entretien, le contrat d\'alternance, changer l\'étape.'],
            self::CANDIDATURES_DELETE => ['Supprimer des candidatures', 'Mettre des candidatures à la corbeille et les restaurer.'],
            self::BOOKLET_MANAGE => ['Suivre le livret d\'apprentissage', 'Saisir les grilles, les visites et les observations du centre, viser les pages du mois et envoyer les liens de suivi à l\'apprenti et au tuteur. « Ses séances » : les apprentis des sessions où le formateur a des séances.'],
            self::SENSITIVE_VIEW => ['Voir les données sensibles des candidats', 'Voir et saisir la RQTH, les besoins d\'adaptation et le compte rendu d\'entretien.'],
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
     * @return list<string>
     */
    public static function scoped(): array
    {
        return [...self::GROUPS['Candidatures'], self::BOOKLET_MANAGE];
    }

    /**
     * @return array<string, list<AccessScope>>
     */
    public static function extraScopes(): array
    {
        return [self::BOOKLET_MANAGE => [AccessScope::Trainer]];
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        $work = [self::CANDIDATURES_VIEW, self::CANDIDATURES_UPDATE];

        return match ($role) {
            Role::AdminOrganisme => self::all(),
            Role::Direction => [self::CANDIDATURES_VIEW, self::SENSITIVE_VIEW],
            Role::Commercial => $work,
            Role::ResponsableDas, Role::ChargeRelations, Role::Assistant => [...$work, self::BOOKLET_MANAGE],
            Role::Formateur => [self::BOOKLET_MANAGE],
        };
    }

    /**
     * @return array<string, AccessScope>
     */
    public static function defaultScopesFor(Role $role): array
    {
        return match ($role) {
            Role::ResponsableDas => array_fill_keys(self::scoped(), AccessScope::Das),
            Role::Formateur => [self::BOOKLET_MANAGE => AccessScope::Trainer],
            default => [],
        };
    }
}
