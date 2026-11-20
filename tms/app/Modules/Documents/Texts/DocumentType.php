<?php

namespace App\Modules\Documents\Texts;

/**
 * The training documents the TMS issues. Delivery 1: the convention (a
 * company contractor) and the contract (a particulier).
 */
enum DocumentType: string
{
    case Convention = 'convention';
    case Contract = 'contract';
    case Convocation = 'convocation';
    case CompletionCertificate = 'completion_certificate';
    case RealisationCertificate = 'realisation_certificate';

    public function label(): string
    {
        return match ($this) {
            self::Convention => 'Convention de formation',
            self::Contract => 'Contrat de formation',
            self::Convocation => 'Convocation',
            self::CompletionCertificate => 'Attestation de fin de formation',
            self::RealisationCertificate => 'Certificat de réalisation',
        };
    }

    /** One per trainee (an enrolment), not per dossier. */
    public function perEnrolment(): bool
    {
        return in_array($this, [self::Convocation, self::CompletionCertificate, self::RealisationCertificate], true);
    }

    /** Signed by its recipient, online too (convention, contract). */
    public function signable(): bool
    {
        return in_array($this, [self::Convention, self::Contract], true);
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
