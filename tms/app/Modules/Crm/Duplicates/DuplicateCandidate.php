<?php

namespace App\Modules\Crm\Duplicates;

/**
 * A fiche that may be the same as the one being entered.
 *
 * reason: siret | email | phone | name ; score in [0, 1] (1 = certain).
 */
final readonly class DuplicateCandidate
{
    public const REASON_LABELS = [
        'siret' => 'Même SIRET',
        'email' => 'Même e-mail',
        'phone' => 'Même téléphone',
        'name' => 'Nom proche, même code postal ou ville',
    ];

    public function __construct(
        public string $id,
        public string $label,
        public string $reason,
        public float $score,
    ) {}

    /**
     * @return array{id: string, label: string, reason: string, reason_label: string, score: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'reason' => $this->reason,
            'reason_label' => self::REASON_LABELS[$this->reason] ?? $this->reason,
            'score' => round($this->score, 2),
        ];
    }
}
