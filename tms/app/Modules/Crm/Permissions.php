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

    /** Theme on the « Rôles et droits » screen. */
    public const GROUP = 'CRM : fiches, pipeline et devis';

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter le CRM', 'Voir les entreprises, personnes, opportunités, devis, actions et le planning.'],
            self::CREATE => ['Créer des fiches', 'Créer entreprises, personnes, opportunités, devis et actions.'],
            self::UPDATE => ['Modifier', 'Modifier les fiches, opportunités, devis (envoi, réponse du client) et actions.'],
            self::DELETE => ['Supprimer', 'Mettre des fiches, opportunités ou devis à la corbeille, et les restaurer.'],
            self::MERGE => ['Fusionner les doublons', 'Fusionner deux fiches en double (définitif).'],
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
