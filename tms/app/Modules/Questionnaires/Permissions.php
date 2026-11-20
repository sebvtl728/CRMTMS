<?php

namespace App\Modules\Questionnaires;

use App\Modules\Core\Access\Role;

/**
 * Rights of the Questionnaires module: read the results of every session
 * (page « Questionnaires », export) and keep the templates and the delay of
 * the cold questionnaire. Launching or reading the questionnaires of one
 * session follows the rights on that session (Sessions\Public\Surveys).
 */
final class Permissions
{
    public const VIEW = 'questionnaires.view';

    public const MANAGE = 'questionnaires.manage';

    public const GROUP = 'Questionnaires';

    /** @return array<string, array{0: string, 1: string}> */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter les résultats des questionnaires', 'Voir les résultats de satisfaction de toutes les sessions et les exporter.'],
            self::MANAGE => ['Paramétrer les questionnaires', 'Créer et modifier les modèles de questionnaires, choisir ceux par défaut et le délai du questionnaire à froid.'],
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
            Role::ResponsableDas, Role::Assistant => [self::VIEW],
            default => [],
        };
    }
}
