<?php

namespace App\Modules\Connectors\Sirene;

/**
 * What the company directory says about one establishment (a legal person's
 * public data; never the managers). Labels are the directory's own wording,
 * mapped to the organisme's references by the caller.
 */
final readonly class CompanyLookupResult
{
    /**
     * @param  list<string>  $idccCodes
     */
    public function __construct(
        public string $name,
        public string $siret,
        public ?string $nafCode,
        public ?string $legalFormLabel,
        public ?string $addressLine1,
        public ?string $postalCode,
        public ?string $city,
        public ?string $employeeRangeLabel,
        /** Lower / upper bound of the employee range (null upper = open-ended). */
        public ?int $employeeMin = null,
        public ?int $employeeMax = null,
        /** The directory's legal-form code (kept so a sole trader is not cached). */
        public ?string $legalFormCode = null,
        /** IDCC of the establishment (collective agreements, 4 digits), DSN placeholders removed. */
        public array $idccCodes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    public static function fromArray(array $a): self
    {
        return new self(
            name: (string) $a['name'],
            siret: (string) $a['siret'],
            nafCode: $a['nafCode'] ?? null,
            legalFormLabel: $a['legalFormLabel'] ?? null,
            addressLine1: $a['addressLine1'] ?? null,
            postalCode: $a['postalCode'] ?? null,
            city: $a['city'] ?? null,
            employeeRangeLabel: $a['employeeRangeLabel'] ?? null,
            employeeMin: $a['employeeMin'] ?? null,
            employeeMax: $a['employeeMax'] ?? null,
            legalFormCode: $a['legalFormCode'] ?? null,
            idccCodes: array_values(array_filter((array) ($a['idccCodes'] ?? []), 'is_string')),
        );
    }
}
