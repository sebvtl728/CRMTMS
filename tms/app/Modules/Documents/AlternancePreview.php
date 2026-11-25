<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Brand;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Documents\Texts\DocumentType;

/**
 * The alternance convention before the dossier exists (Core\Documents\
 * Previews « alternance_convention »): the same frame and texts as the
 * issued one, for the default company and the employer of the contract;
 * what only the dossier knows (price, number) is « à compléter ».
 */
final class AlternancePreview
{
    public const KEY = 'alternance_convention';

    public function __construct(private readonly DocumentPdf $pdf, private readonly Fiches $fiches) {}

    /**
     * @param  array{alternance: array<string, mixed>, training_label: string, sessions: list<array{label: string, starts_on: string, ends_on: string, planned_minutes: ?int, place: ?string}>}  $data
     */
    public function render(array $data): string
    {
        $alternance = $data['alternance'];
        $company = Brand::company() ?? throw new UserFacingHttpException(409, 'Indiquez la société par défaut (Administration › Sociétés).');
        $employer = (string) $alternance['employer_organisation_id'];
        $identity = $this->fiches->billingIdentity('organisation', $employer)
            ?? throw new UserFacingHttpException(409, 'L\'entreprise d\'accueil est introuvable.');
        $recipient = [
            'name' => $identity['name'],
            'attention' => null,
            'address_line1' => $identity['address_line1'],
            'address_line2' => $identity['address_line2'],
            'postal_code' => $identity['postal_code'],
            'city' => $identity['city'],
            'email' => $identity['email'],
            'person_id' => null,
            ...($this->fiches->organisationCodes($employer) ?? []),
        ];
        $dossier = [
            'id' => null,
            'number' => null,
            'price_cents' => null,
            'contractor' => ['type' => 'organisation', 'id' => $employer],
            'lines' => [['kind' => 'training', 'label' => $data['training_label'], 'total_cents' => null]],
            'sessions' => $data['sessions'],
            'trainees' => [['person_id' => $alternance['apprentice_person_id']]],
            'fundings' => [],
            'alternance' => $alternance,
        ];

        return $this->pdf->preview(DocumentType::forContract((string) $alternance['contract_type']), $dossier, $company, $recipient);
    }
}
