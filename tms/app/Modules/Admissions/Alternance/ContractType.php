<?php

namespace App\Modules\Admissions\Alternance;

/** The two alternance contracts, fixed by the law. */
enum ContractType: string
{
    case Apprenticeship = 'apprenticeship';
    case Professionalisation = 'professionalisation';

    public function label(): string
    {
        return match ($this) {
            self::Apprenticeship => 'Contrat d\'apprentissage',
            self::Professionalisation => 'Contrat de professionnalisation',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
