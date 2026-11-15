<?php

namespace App\Modules\Core\Access;

/**
 * How far a scoped permission reaches: the user's own records, those of the
 * DAS he follows, or the whole organisme.
 */
enum AccessScope: string
{
    case Own = 'own';
    case Das = 'das';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Ses fiches',
            self::Das => 'Ses DAS',
            self::All => "Tout l'organisme",
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $scope) => ['value' => $scope->value, 'label' => $scope->label()], self::cases());
    }

    /** Wider is greater: all > das > own. */
    public function rank(): int
    {
        return match ($this) {
            self::Own => 1,
            self::Das => 2,
            self::All => 3,
        };
    }
}
