<?php

namespace App\Modules\Core\Access;

/**
 * The seven system roles of every organisme (one primary role per user). Each
 * tenant has its own spatie role records (RoleSeeder, RoleModel with
 * system = true), named after these values; the default permissions are in
 * Permissions::defaultsFor(). An organisme adds its own roles with
 * RoleCatalogue (RoleModel, never an enum case).
 */
enum Role: string
{
    case AdminOrganisme = 'admin_organisme';
    case Direction = 'direction';
    case Commercial = 'commercial';
    case ChargeRelations = 'charge_relations';
    case Assistant = 'assistant';
    case ResponsableDas = 'responsable_das';
    /** Trainer (0.8.0): his séances and their attendance only (scope « Ses séances »). */
    case Formateur = 'formateur';

    public function label(): string
    {
        return match ($this) {
            self::AdminOrganisme => 'Administrateur de l\'organisme',
            self::Direction => 'Direction',
            self::Commercial => 'Commercial',
            self::ChargeRelations => 'Chargé de relations',
            self::Assistant => 'Assistant',
            self::ResponsableDas => 'Responsable DAS',
            self::Formateur => 'Formateur',
        };
    }

    public function requires2fa(): bool
    {
        return in_array($this, [self::AdminOrganisme, self::Direction], true);
    }
}
