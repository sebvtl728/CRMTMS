<?php

namespace App\Modules\Core\Access;

/**
 * Roles of an account inside its organisme (one primary role per user in
 * lot 1a). Each tenant has its own spatie role records (RoleSeeder), named
 * after these values; the default permissions are in Permissions::defaultsFor().
 */
enum Role: string
{
    case AdminOrganisme = 'admin_organisme';
    case Direction = 'direction';
    case Commercial = 'commercial';
    case ChargeRelations = 'charge_relations';
    case Assistant = 'assistant';
    case ResponsableDas = 'responsable_das';

    public function label(): string
    {
        return match ($this) {
            self::AdminOrganisme => 'Administrateur de l\'organisme',
            self::Direction => 'Direction',
            self::Commercial => 'Commercial',
            self::ChargeRelations => 'Chargé de relations',
            self::Assistant => 'Assistant',
            self::ResponsableDas => 'Responsable DAS',
        };
    }

    public function requires2fa(): bool
    {
        return in_array($this, [self::AdminOrganisme, self::Direction], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()], self::cases());
    }
}
