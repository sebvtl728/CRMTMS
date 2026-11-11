<?php

namespace App\Modules\Core\Companies;

enum LegalForm: string
{
    case Sarl = 'sarl';
    case Sas = 'sas';
    case Sasu = 'sasu';
    case Sa = 'sa';
    case Eurl = 'eurl';
    case Ei = 'ei';
    case Scop = 'scop';
    case Scic = 'scic';
    case Association = 'association';
    case Collectivite = 'collectivite';
    case EtablissementPublic = 'etablissement_public';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Association => 'Association',
            self::Collectivite => 'Collectivité territoriale',
            self::EtablissementPublic => 'Établissement public',
            self::Autre => 'Autre',
            default => mb_strtoupper($this->value),
        };
    }

    /**
     * A cooperative (SCOP / SCIC) is a SARL, SA or SAS underneath.
     */
    public function isCooperative(): bool
    {
        return $this === self::Scop || $this === self::Scic;
    }

    /**
     * @return list<string>
     */
    public static function underlyingForms(): array
    {
        return ['sarl', 'sa', 'sas'];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $f) => ['value' => $f->value, 'label' => $f->label()], self::cases());
    }
}
