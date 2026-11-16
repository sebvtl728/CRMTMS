<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV export of the dossiers list (same query, so same filters and scope).
 * Names of the contractants, contacts, trainings and DAS are read through
 * the public services, once per id (memoised for the whole file).
 */
final class DossierExport implements ExportResource
{
    /** @var array<string, string> */
    private array $organisations = [];

    /** @var array<string, string> */
    private array $people = [];

    /** @var array<string, string> */
    private array $trainings = [];

    /** @var array<string, string> */
    private array $das = [];

    public function fileName(): string
    {
        return 'dossiers';
    }

    public function sheetName(): string
    {
        return 'Dossiers';
    }

    public function columns(): array
    {
        return [
            'number' => 'Numéro',
            'created_on' => 'Créé le',
            'account' => 'Contractant',
            'account_type' => 'Type de contractant',
            'contact' => 'Contact',
            'company' => 'Société émettrice',
            'das' => 'DAS',
            'training_titles' => 'Formations',
            'price' => 'Prix HT (€)',
            'status' => 'État',
            'responsible' => 'Responsable',
            'convention_sent_on' => 'Convention envoyée le',
            'convention_signed_on' => 'Convention signée le',
            'convocations_sent_on' => 'Convocations envoyées le',
        ];
    }

    public function defaults(): array
    {
        return array_keys($this->columns());
    }

    public function with(User $user): array
    {
        return ['lines', 'responsible:id,name', 'company:id,name'];
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        assert($model instanceof Dossier);

        return match ($column) {
            'number' => $model->number,
            'created_on' => ExportCell::date($model->created_at),
            'account' => $model->account_organisation_id !== null
                ? $this->organisation($model->account_organisation_id)
                : $this->person((string) $model->account_person_id),
            'account_type' => $model->account_organisation_id !== null ? 'Entreprise' : 'Particulier',
            'contact' => $model->contact_person_id === null ? null : $this->person($model->contact_person_id),
            'company' => $model->company?->name,
            'das' => $this->das($model->das_id),
            'training_titles' => $model->lines->filter(fn (DossierLine $l) => $l->isTraining())
                ->map(fn (DossierLine $l) => $this->training((string) $l->training_id) ?? $l->label)->unique()->implode(', '),
            'price' => ExportCell::amount(Amounts::decimal($model->priceCents())),
            // The state shown by the list.
            'status' => $model->effectiveStatus(Dossier::today())->label(),
            'responsible' => $model->responsible?->name,
            'convention_sent_on' => ExportCell::date($model->convention_sent_on),
            'convention_signed_on' => ExportCell::date($model->convention_signed_on),
            'convocations_sent_on' => ExportCell::date($model->convocations_sent_on),
            default => null,
        };
    }

    private function organisation(string $id): string
    {
        return $this->organisations[$id] ??= app(Fiches::class)->organisationLabels([$id])[$id]['label'] ?? '';
    }

    private function person(string $id): string
    {
        return $this->people[$id] ??= app(Fiches::class)->personLabels([$id])[$id]['label'] ?? '';
    }

    private function training(string $id): ?string
    {
        if (! array_key_exists($id, $this->trainings)) {
            $this->trainings[$id] = app(Trainings::class)->find($id)->title ?? '';
        }

        return $this->trainings[$id] === '' ? null : $this->trainings[$id];
    }

    private function das(string $id): string
    {
        return $this->das[$id] ??= (string) ReferenceItem::query()->whereKey($id)->value('label');
    }
}
