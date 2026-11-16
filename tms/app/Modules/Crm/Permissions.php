<?php

namespace App\Modules\Crm;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Users\User;

/**
 * Permissions of the CRM module and their default matrix, registered into
 * the Core catalogue by CrmServiceProvider, one theme of the « Rôles et
 * droits » screen per domain.
 *
 * Each domain (fiches, opportunités, devis, actions) has its own view /
 * update / delete rights; creating is part of « update ». The domain rights
 * and the revenue view carry a scope (own / das / all, AccessScope).
 */
final class Permissions
{
    /** Entreprises, personnes, rattachements, notes, pièces jointes, doublons, consentements. */
    public const FICHES_VIEW = 'crm.fiches.view';

    public const FICHES_UPDATE = 'crm.fiches.update';

    public const FICHES_DELETE = 'crm.fiches.delete';

    /** Opportunités, kanban, étapes, formations d'une opportunité. */
    public const OPPORTUNITIES_VIEW = 'crm.opportunities.view';

    public const OPPORTUNITIES_UPDATE = 'crm.opportunities.update';

    public const OPPORTUNITIES_DELETE = 'crm.opportunities.delete';

    /** Devis et lignes, PDF, copie signée, envoi, réponse du client. */
    public const QUOTES_VIEW = 'crm.quotes.view';

    public const QUOTES_UPDATE = 'crm.quotes.update';

    public const QUOTES_DELETE = 'crm.quotes.delete';

    /** Actions, « À faire », planning, compte rendu de RDV, agenda. */
    public const ACTIVITIES_VIEW = 'crm.activities.view';

    public const ACTIVITIES_UPDATE = 'crm.activities.update';

    public const ACTIVITIES_DELETE = 'crm.activities.delete';

    /** « À faire » and planning of the whole team. */
    public const ACTIVITIES_TEAM = 'crm.activities.team';

    public const REVENUE_VIEW = 'crm.revenue.view';

    /** Revenue targets. */
    public const REVENUE_MANAGE = 'crm.revenue.manage';

    public const MERGE = 'crm.merge';

    public const IMPORT = 'crm.import';

    public const EXPORT = 'crm.export';

    public const GDPR = 'crm.gdpr';

    /** Gate (not a permission): at least one « Consulter » right, see anyView(). */
    public const ANY_VIEW = 'crm.any-view';

    /**
     * Themes of the « Rôles et droits » screen, in order: theme => permissions.
     *
     * @var array<string, list<string>>
     */
    /** Theme registered after the Catalogue's (see CrmServiceProvider). */
    public const DATA_GROUP = 'Imports, exports et RGPD';

    public const GROUPS = [
        'Entreprises et personnes' => [self::FICHES_VIEW, self::FICHES_UPDATE, self::FICHES_DELETE, self::MERGE],
        'Opportunités' => [self::OPPORTUNITIES_VIEW, self::OPPORTUNITIES_UPDATE, self::OPPORTUNITIES_DELETE],
        'Devis' => [self::QUOTES_VIEW, self::QUOTES_UPDATE, self::QUOTES_DELETE],
        'Actions et agenda' => [self::ACTIVITIES_VIEW, self::ACTIVITIES_UPDATE, self::ACTIVITIES_DELETE, self::ACTIVITIES_TEAM],
        "Chiffre d'affaires" => [self::REVENUE_VIEW, self::REVENUE_MANAGE],
        self::DATA_GROUP => [self::IMPORT, self::EXPORT, self::GDPR],
    ];

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::FICHES_VIEW => ['Consulter les entreprises et personnes', 'Voir les fiches entreprises et personnes, leurs rattachements, notes et pièces jointes.'],
            self::FICHES_UPDATE => ['Créer et modifier les entreprises et personnes', 'Créer et modifier les fiches, leurs rattachements, notes, pièces jointes et consentements.'],
            self::FICHES_DELETE => ['Supprimer des entreprises et personnes', 'Mettre des fiches à la corbeille, les restaurer et consulter la corbeille.'],
            self::MERGE => ['Fusionner les doublons', 'Fusionner deux fiches en double (définitif).'],
            self::OPPORTUNITIES_VIEW => ['Consulter les opportunités', 'Voir les opportunités, le pipeline et leurs changements d\'étape.'],
            self::OPPORTUNITIES_UPDATE => ['Créer et modifier les opportunités', 'Créer et modifier les opportunités, changer leur étape et leurs formations.'],
            self::OPPORTUNITIES_DELETE => ['Supprimer des opportunités', 'Mettre des opportunités à la corbeille, et consulter celles des fiches à la corbeille.'],
            self::QUOTES_VIEW => ['Consulter les devis', 'Voir les devis, leur PDF et leur copie signée.'],
            self::QUOTES_UPDATE => ['Créer et modifier les devis', 'Créer, modifier et dupliquer les devis, les envoyer et enregistrer la réponse du client.'],
            self::QUOTES_DELETE => ['Supprimer des devis', 'Mettre des devis brouillons à la corbeille, et consulter ceux des fiches à la corbeille.'],
            self::ACTIVITIES_VIEW => ['Consulter les actions', 'Voir les actions, « À faire », le planning et l\'agenda.'],
            self::ACTIVITIES_UPDATE => ['Créer et modifier les actions', 'Créer, modifier, reporter et terminer des actions, saisir un compte rendu de RDV.'],
            self::ACTIVITIES_DELETE => ['Supprimer des actions', 'Supprimer des actions.'],
            self::ACTIVITIES_TEAM => ['Voir les actions de l\'équipe', 'Afficher « À faire » et le planning de toute l\'équipe.'],
            self::REVENUE_VIEW => ['Consulter le chiffre d\'affaires', 'Voir le chiffre d\'affaires signé par DAS.'],
            self::REVENUE_MANAGE => ['Gérer les objectifs de chiffre d\'affaires', 'Fixer les objectifs de chiffre d\'affaires de chaque DAS.'],
            self::IMPORT => ['Importer des fichiers', 'Importer des fichiers CSV ou Excel et annuler un import.'],
            self::EXPORT => ['Exporter vers Excel', 'Télécharger les listes filtrées au format Excel.'],
            self::GDPR => ['Gérer le RGPD', "Exporter les données d'une personne, l'anonymiser, gérer les durées de conservation."],
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
     * The four « Consulter » rights: holding one opens the CRM (menu,
     * global search, « Accueil »).
     *
     * @return list<string>
     */
    public static function views(): array
    {
        return [self::FICHES_VIEW, self::OPPORTUNITIES_VIEW, self::QUOTES_VIEW, self::ACTIVITIES_VIEW];
    }

    /**
     * The domain rights (view / update / delete of the four domains).
     *
     * @return list<string>
     */
    public static function domains(): array
    {
        return [
            self::FICHES_VIEW, self::FICHES_UPDATE, self::FICHES_DELETE,
            self::OPPORTUNITIES_VIEW, self::OPPORTUNITIES_UPDATE, self::OPPORTUNITIES_DELETE,
            self::QUOTES_VIEW, self::QUOTES_UPDATE, self::QUOTES_DELETE,
            self::ACTIVITIES_VIEW, self::ACTIVITIES_UPDATE, self::ACTIVITIES_DELETE,
        ];
    }

    /**
     * Permissions carrying a scope (own / das / all).
     *
     * @return list<string>
     */
    public static function scoped(): array
    {
        return [...self::domains(), self::REVENUE_VIEW];
    }

    /**
     * At least one « Consulter » right of a domain.
     */
    public static function anyView(User $user): bool
    {
        foreach (self::views() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        $views = self::views();
        $updates = [self::FICHES_UPDATE, self::OPPORTUNITIES_UPDATE, self::QUOTES_UPDATE, self::ACTIVITIES_UPDATE];

        return match ($role) {
            Role::AdminOrganisme => self::all(),
            Role::Direction => [...$views, self::ACTIVITIES_TEAM, self::REVENUE_VIEW, self::REVENUE_MANAGE, self::EXPORT, self::GDPR],
            Role::Commercial, Role::ChargeRelations => [...self::domains(), self::MERGE, self::REVENUE_VIEW, self::IMPORT, self::EXPORT],
            // Removing an action was part of « Modifier » before 0.5.0.
            Role::Assistant => [...$views, ...$updates, self::ACTIVITIES_DELETE],
            Role::ResponsableDas => [...$views, self::REVENUE_VIEW, self::EXPORT],
            // A trainer reaches his séances only, never the CRM.
            Role::Formateur => [],
        };
    }

    /**
     * Default scope of the scoped permissions (missing: the whole organisme).
     * The Responsable DAS works in the DAS he follows, whatever he is granted.
     *
     * @return array<string, AccessScope>
     */
    public static function defaultScopesFor(Role $role): array
    {
        return match ($role) {
            Role::Commercial, Role::ChargeRelations => [self::REVENUE_VIEW => AccessScope::Own],
            Role::ResponsableDas => array_fill_keys(self::scoped(), AccessScope::Das),
            default => [],
        };
    }
}
