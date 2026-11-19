<?php

namespace App\Modules\Qualite;

use App\Modules\Core\Access\Role;

/**
 * Rights of the Qualité module: see the Qualiopi file, and keep it
 * (statuses, applicability, proofs, export). By default the administrator
 * and the direction only: an organisme that does not use the module grants
 * nothing (that is its « option »).
 */
final class Permissions
{
    public const VIEW = 'qualite.view';

    public const MANAGE = 'qualite.manage';

    public const GROUP = 'Qualité';

    /** @return array<string, array{0: string, 1: string}> */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter le dossier Qualiopi', 'Voir les indicateurs, leurs statuts, mesures et preuves.'],
            self::MANAGE => ['Tenir le dossier Qualiopi', 'Changer statut, applicabilité et responsable d’un indicateur, déposer et retirer des preuves, exporter le dossier d’audit.'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE];
    }

    /** @return list<string> */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme, Role::Direction => [self::VIEW, self::MANAGE],
            default => [],
        };
    }
}
