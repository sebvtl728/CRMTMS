<?php

namespace App\Modules\Crm\Export;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Database\Eloquent\Model;

final class OrganisationExport implements ExportResource
{
    public function fileName(): string
    {
        return 'entreprises';
    }

    public function sheetName(): string
    {
        return 'Entreprises';
    }

    public function columns(): array
    {
        return [
            'name' => 'Nom',
            'siret' => 'SIRET',
            'naf_code' => 'Code NAF',
            'status' => 'Statut',
            'legal_form' => 'Forme juridique',
            'sector' => 'Secteur',
            'establishment_type' => "Type d'établissement",
            'company_size' => 'Taille',
            'origin' => 'Origine',
            'responsible' => 'Responsable',
            'das' => 'DAS',
            'email' => 'E-mail',
            'phone' => 'Téléphone',
            'website' => 'Site web',
            'address' => 'Adresse',
            'postal_code' => 'Code postal',
            'city' => 'Ville',
            'country' => 'Pays',
            'last_contact_at' => 'Dernier contact',
            'created_at' => 'Création',
        ];
    }

    public function defaults(): array
    {
        return ['name', 'siret', 'status', 'sector', 'responsible', 'das', 'email', 'phone', 'postal_code', 'city', 'last_contact_at'];
    }

    public function with(User $user): array
    {
        return ['legalForm', 'sector', 'establishmentType', 'companySize', 'origin', 'responsible', 'das'];
    }

    public function value(Model $model, string $column): ?string
    {
        assert($model instanceof Organisation);

        return match ($column) {
            'name' => $model->name,
            'siret' => $model->siret,
            'naf_code' => $model->naf_code,
            'status' => $model->status->label(),
            'legal_form' => $model->legalForm?->label,
            'sector' => $model->sector?->label,
            'establishment_type' => $model->establishmentType?->label,
            'company_size' => $model->companySize?->label,
            'origin' => $model->origin?->label,
            'responsible' => $model->responsible?->name,
            'das' => $model->das->pluck('label')->implode(', ') ?: null,
            'email' => $model->email,
            'phone' => $model->phone,
            'website' => $model->website,
            'address' => collect([$model->address_line1, $model->address_line2])->filter()->implode(', ') ?: null,
            'postal_code' => $model->postal_code,
            'city' => $model->city,
            'country' => $model->country,
            'last_contact_at' => $model->last_contact_at?->format('d/m/Y'),
            'created_at' => $model->created_at?->format('d/m/Y'),
            default => null,
        };
    }
}
