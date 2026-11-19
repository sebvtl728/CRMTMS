<?php

namespace App\Modules\Admissions\Candidatures;

/**
 * The pathway a candidate aims at, fixed in the code (it drives the page):
 * an alternance contract, or continuing training as a trainee. Null while
 * undecided. A candidature with a contract is always « alternance ».
 */
enum Pathway: string
{
    case Alternance = 'alternance';
    case Trainee = 'trainee';

    public function label(): string
    {
        return match ($this) {
            self::Alternance => 'Alternance',
            self::Trainee => 'Stagiaire',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], self::cases());
    }
}
