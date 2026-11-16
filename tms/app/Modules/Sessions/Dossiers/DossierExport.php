<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Exports\PrefetchesRows;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * CSV export of the dossiers list (same query, so same filters and scope).
 * Names of the contractants, contacts, trainings and DAS, and the span of
 * the sessions (effective state), are read for each chunk of rows in one
 * query per source (prefetch()): no query per row. Amounts in French
 * (Amounts::french: integer cents, never a float).
 */
final class DossierExport implements ExportResource, PrefetchesRows
{
    /** @var array<string, array{label: string, trashed: bool}> */
    private array $organisations = [];

    /** @var array<string, array{label: string, trashed: bool}> */
    private array $people = [];

    /** @var array<string, string> */
    private array $trainings = [];

    /** @var array<string, string> */
    private array $das = [];

    /** @var array<string, array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}> */
    private array $spans = [];

    private ?CarbonImmutable $today = null;

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

    public function prefetch(array $models): void
    {
        $dossiers = collect($models)->filter(fn ($m) => $m instanceof Dossier);
        $this->organisations = app(Fiches::class)->organisationLabels($dossiers->pluck('account_organisation_id')->filter()->unique()->values()->all());
        $this->people = app(Fiches::class)->personLabels($dossiers->flatMap(fn (Dossier $d) => [$d->account_person_id, $d->contact_person_id])->filter()->unique()->values()->all());
        $trainingIds = $dossiers->flatMap(fn (Dossier $d) => $d->lines->pluck('training_id'))->filter()->unique()->values()->all();
        $this->trainings = array_map(fn ($t) => $t->title, app(Trainings::class)->findMany($trainingIds));
        $this->das = ReferenceItem::query()->whereKey($dossiers->pluck('das_id')->unique()->values()->all())->pluck('label', 'id')->all();
        $this->spans = DossierSchedule::spans($dossiers->pluck('id')->values()->all());
        $this->today ??= Dossier::today();
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        assert($model instanceof Dossier);

        return match ($column) {
            'number' => $model->number,
            'created_on' => ExportCell::date($model->created_at),
            'account' => $model->account_organisation_id !== null
                ? ($this->organisations[$model->account_organisation_id]['label'] ?? '')
                : ($this->people[(string) $model->account_person_id]['label'] ?? ''),
            'account_type' => $model->account_organisation_id !== null ? 'Entreprise' : 'Particulier',
            'contact' => $model->contact_person_id === null ? null : ($this->people[$model->contact_person_id]['label'] ?? ''),
            'company' => $model->company?->name,
            'das' => $this->das[$model->das_id] ?? '',
            'training_titles' => $model->lines->filter(fn (DossierLine $l) => $l->isTraining())
                ->map(fn (DossierLine $l) => $this->trainings[(string) $l->training_id] ?? $l->label)->unique()->implode(', '),
            'price' => Amounts::french($model->priceCents()),
            // The state shown by the list.
            'status' => $model->effectiveStatus($this->today ?? Dossier::today(), $this->spans[$model->id] ?? null)->label(),
            'responsible' => $model->responsible?->name,
            'convention_sent_on' => ExportCell::date($model->convention_sent_on),
            'convention_signed_on' => ExportCell::date($model->convention_signed_on),
            'convocations_sent_on' => ExportCell::date($model->convocations_sent_on),
            default => null,
        };
    }
}
