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

    /**
     * Themes of the « Rôles et droits » screen, in order: theme => permissions.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'Candidatures' => [self::CANDIDATURES_VIEW, self::CANDIDATURES_UPDATE, self::CANDIDATURES_DELETE],
        'Données sensibles des candidats' => [self::SENSITIVE_VIEW],
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
        return self::GROUPS['Candidatures'];
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
            Role::ResponsableDas, Role::Commercial, Role::ChargeRelations, Role::Assistant => $work,
            Role::Formateur => [],
        };
    }

    /**
     * @return array<string, AccessScope>
     */
    public static function defaultScopesFor(Role $role): array
    {
        return $role === Role::ResponsableDas ? array_fill_keys(self::scoped(), AccessScope::Das) : [];
    }
}
