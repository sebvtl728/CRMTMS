<?php

namespace App\Modules\Admissions\Candidatures;

/**
 * Stages of a candidature, fixed in the code. The path runs identified →
 * positioning_sent → positioning_received → interview → admitted; rejected
 * and withdrawn leave it. « Stagiaire » and « Alternant » are not stages:
 * they are read from the dossier and the contract.
 */
enum CandidatureStatus: string
{
    case Identified = 'identified';
    case PositioningSent = 'positioning_sent';
    case PositioningReceived = 'positioning_received';
    case Interview = 'interview';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Identified => 'Identifié',
            self::PositioningSent => 'Test envoyé',
            self::PositioningReceived => 'Test reçu',
            self::Interview => 'Entretien',
            self::Admitted => 'Admis',
            self::Rejected => 'Non retenu',
            self::Withdrawn => 'Désisté',
        };
    }

    /** Place on the path (0 to 4), null for the exits. */
    public function rank(): ?int
    {
        return match ($this) {
            self::Identified => 0,
            self::PositioningSent => 1,
            self::PositioningReceived => 2,
            self::Interview => 3,
            self::Admitted => 4,
            self::Rejected, self::Withdrawn => null,
        };
    }

    public function isExit(): bool
    {
        return $this->rank() === null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
