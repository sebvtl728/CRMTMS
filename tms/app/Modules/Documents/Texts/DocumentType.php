<?php

namespace App\Modules\Documents\Texts;

/**
 * The training documents the TMS issues. Delivery 1: the convention (a
 * company contractor) and the contract (a particulier); the conventions of an
 * alternance dossier (apprenticeship, professionalisation) replace the
 * convention there. Per trainee: convocation, attestation, certificate, and
 * the forms issued from the dossier (individual contract, internal rules,
 * image rights).
 */
enum DocumentType: string
{
    case Convention = 'convention';
    case Contract = 'contract';
    case Convocation = 'convocation';
    case CompletionCertificate = 'completion_certificate';
    case RealisationCertificate = 'realisation_certificate';
    case ApprenticeshipConvention = 'apprenticeship_convention';
    case ProfessionalisationConvention = 'professionalisation_convention';
    case IndividualContract = 'individual_contract';
    case InternalRules = 'internal_rules';
    case ImageRights = 'image_rights';

    public function label(): string
    {
        return match ($this) {
            self::Convention => 'Convention de formation',
            self::Contract => 'Contrat de formation',
            self::Convocation => 'Convocation',
            self::CompletionCertificate => 'Attestation de fin de formation',
            self::RealisationCertificate => 'Certificat de réalisation',
            self::ApprenticeshipConvention => 'Convention de formation – apprentissage',
            self::ProfessionalisationConvention => 'Convention de formation – professionnalisation',
            self::IndividualContract => 'Contrat individuel de formation',
            self::InternalRules => 'Règlement intérieur et charte du stagiaire',
            self::ImageRights => 'Autorisation de droit à l\'image',
        };
    }

    /** One per trainee (an enrolment), not per dossier. */
    public function perEnrolment(): bool
    {
        return in_array($this, [self::Convocation, self::CompletionCertificate, self::RealisationCertificate, ...self::fromDossier()], true);
    }

    /**
     * The per-trainee documents issued from the dossier page, one for each
     * of its trainees (individual contract, internal rules, image rights).
     *
     * @return list<self>
     */
    public static function fromDossier(): array
    {
        return [self::IndividualContract, self::InternalRules, self::ImageRights];
    }

    /** The attestation and the certificate: issued at the end of the training or when the trainee leaves. */
    public function afterTraining(): bool
    {
        return $this === self::CompletionCertificate || $this === self::RealisationCertificate;
    }

    /** Signed by its recipient, online too (conventions, contracts, the trainee's forms). */
    public function signable(): bool
    {
        return in_array($this, [self::Convention, self::Contract, self::ApprenticeshipConvention, self::ProfessionalisationConvention, ...self::fromDossier()], true);
    }

    /** A convention of an alternance dossier (CFA ↔ employer), chosen by the contract type. */
    public function isAlternance(): bool
    {
        return in_array($this, [self::ApprenticeshipConvention, self::ProfessionalisationConvention], true);
    }

    /** Addressed to the entreprise contractor and its contact (the classic and the alternance conventions). */
    public function forCompany(): bool
    {
        return $this === self::Convention || $this->isAlternance();
    }

    /** The alternance convention of a contract type (apprenticeship, professionalisation). */
    public static function forContract(string $contractType): self
    {
        return $contractType === 'professionalisation' ? self::ProfessionalisationConvention : self::ApprenticeshipConvention;
    }

    /** The organisme may adapt its texts (never the ministry's certificate). */
    public function editable(): bool
    {
        return $this !== self::RealisationCertificate;
    }

    /**
     * @param  list<self>|null  $types  null: every type
     * @return list<array{value: string, label: string}>
     */
    public static function options(?array $types = null): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], $types ?? self::cases());
    }
}
