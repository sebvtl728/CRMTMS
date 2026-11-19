<?php

namespace App\Modules\Documents;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;

/**
 * Permissions of the Documents module (0.10): training documents of a
 * dossier (convention, contract…). Both rights carry the scope of the
 * dossier (own: responsible for it or created it; das: its DAS).
 */
final class Permissions
{
    /** See and download the documents of the dossiers. */
    public const VIEW = 'documents.view';

    /** Issue, send, have signed and cancel documents. */
    public const MANAGE = 'documents.manage';

    /** Theme on the « Rôles et droits » screen. */
    public const GROUP = 'Documents';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter les documents', 'Voir et télécharger les documents des dossiers : conventions, contrats et leurs envois.'],
            self::MANAGE => ['Émettre les documents', 'Émettre, envoyer et annuler les documents des dossiers. Un document émis ne se modifie plus.'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE];
    }

    /** @return list<string> */
    public static function scoped(): array
    {
        return [self::VIEW, self::MANAGE];
    }

    /** @return list<string> */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme, Role::Direction, Role::Assistant, Role::ResponsableDas => self::all(),
            Role::Commercial, Role::ChargeRelations => [self::VIEW],
            Role::Formateur => [],
        };
    }

    /** @return array<string, AccessScope> */
    public static function defaultScopesFor(Role $role): array
    {
        return match ($role) {
            Role::ResponsableDas => array_fill_keys(self::scoped(), AccessScope::Das),
            Role::Commercial, Role::ChargeRelations => [self::VIEW => AccessScope::Own],
            default => [],
        };
    }
}
