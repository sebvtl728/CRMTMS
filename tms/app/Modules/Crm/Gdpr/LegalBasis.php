<?php

namespace App\Modules\Crm\Gdpr;

/**
 * Legal basis of the processing of a person's data (GDPR art. 6).
 */
enum LegalBasis: string
{
    case LegitimateInterest = 'interet_legitime';
    case Consent = 'consentement';
    case Contract = 'contrat';
    case LegalObligation = 'obligation_legale';

    public function label(): string
    {
        return match ($this) {
            self::LegitimateInterest => 'Intérêt légitime',
            self::Consent => 'Consentement',
            self::Contract => 'Contrat',
            self::LegalObligation => 'Obligation légale',
        };
    }

    /** Interlocuteur (B2B contact): legitimate interest; particulier: consent. */
    public static function defaultFor(bool $isIndividual): self
    {
        return $isIndividual ? self::Consent : self::LegitimateInterest;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $b) => ['value' => $b->value, 'label' => $b->label()], self::cases());
    }
}
