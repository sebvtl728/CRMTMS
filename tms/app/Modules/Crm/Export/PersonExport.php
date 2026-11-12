<?php

namespace App\Modules\Crm\Export;

use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

final class PersonExport implements ExportResource
{
    public function fileName(): string
    {
        return 'personnes';
    }

    public function sheetName(): string
    {
        return 'Personnes';
    }

    public function columns(): array
    {
        return [
            'civility' => 'Civilité',
            'last_name' => 'Nom',
            'first_name' => 'Prénom',
            'type' => 'Type',
            'organisations' => 'Entreprises',
            'job_title' => 'Fonction',
            'email' => 'E-mail',
            'email_secondary' => 'E-mail secondaire',
            'phone' => 'Téléphone',
            'mobile' => 'Mobile',
            'work_email' => 'E-mail professionnel',
            'work_phone' => 'Téléphone professionnel',
            'address' => 'Adresse',
            'postal_code' => 'Code postal',
            'city' => 'Ville',
            'status' => 'Statut',
            'situation' => 'Situation professionnelle',
            'funding' => 'Mode de financement',
            'origin' => 'Origine',
            'responsible' => 'Responsable',
            'last_contact_at' => 'Dernier contact',
            'created_at' => 'Création',
        ];
    }

    public function defaults(): array
    {
        return ['civility', 'last_name', 'first_name', 'type', 'organisations', 'email', 'mobile', 'phone', 'postal_code', 'city', 'responsible', 'last_contact_at'];
    }

    public function with(): array
    {
        return ['activeAffiliations.organisation', 'professionalSituation', 'fundingMode', 'origin', 'responsible'];
    }

    public function value(Model $model, string $column): ?string
    {
        assert($model instanceof Person);
        $affiliations = $model->activeAffiliations;

        return match ($column) {
            'civility' => $model->civility,
            'last_name' => $model->last_name,
            'first_name' => $model->first_name,
            'type' => collect([$model->is_individual ? 'Particulier' : null, $affiliations->isNotEmpty() ? 'Interlocuteur' : null])->filter()->implode(', ') ?: null,
            'organisations' => $affiliations->map(fn ($a) => $a->organisation?->name)->filter()->unique()->implode(', ') ?: null,
            'job_title' => $affiliations->pluck('job_title')->filter()->unique()->implode(', ') ?: null,
            'email' => $model->email,
            'email_secondary' => $model->email_secondary,
            'phone' => PhoneNumber::display($model->phone),
            'mobile' => PhoneNumber::display($model->mobile),
            'work_email' => $affiliations->pluck('work_email')->filter()->unique()->implode(', ') ?: null,
            'work_phone' => $affiliations->pluck('work_phone')->filter()->map(fn ($p) => PhoneNumber::display($p))->unique()->implode(', ') ?: null,
            'address' => collect([$model->address_line1, $model->address_line2])->filter()->implode(', ') ?: null,
            'postal_code' => $model->postal_code,
            'city' => $model->city,
            'status' => $model->status->label(),
            'situation' => $model->professionalSituation?->label,
            'funding' => $model->fundingMode?->label,
            'origin' => $model->origin?->label,
            'responsible' => $model->responsible?->name,
            'last_contact_at' => $model->last_contact_at?->format('d/m/Y'),
            'created_at' => $model->created_at?->format('d/m/Y'),
            default => null,
        };
    }
}
