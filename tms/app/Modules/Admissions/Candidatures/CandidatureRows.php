<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Crm\CandidaturesBlock;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Public\Billing;

/**
 * What the list and the export show of a page of candidatures, read in a few
 * grouped queries (people, trainings, DAS, users, contracts, dossiers).
 * Never a sensitive value.
 */
final class CandidatureRows
{
    /** @var array<string, array<string, mixed>> */
    private array $people = [];

    /** @var array<string, string> */
    private array $trainings = [];

    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<string, string> */
    private array $users = [];

    /** @var array<string, AlternanceContract> */
    private array $contracts = [];

    /** @var array<string, array{label: string, trashed: bool}> */
    private array $employers = [];

    /** @var array<string, array{number: string, trashed: bool}> */
    private array $dossiers = [];

    /**
     * @param  iterable<Candidature>  $candidatures
     */
    public function prefetch(iterable $candidatures): void
    {
        $list = collect($candidatures);
        $fiches = app(Fiches::class);
        $this->people = $fiches->personLabels($list->pluck('person_id')->unique()->values()->all());
        foreach (app(Trainings::class)->findMany(array_values(array_filter($list->pluck('training_id')->unique()->all()))) as $id => $t) {
            $this->trainings[$id] = $t->title;
        }
        $this->labels = ReferenceItem::withTrashed()->whereKey($list->pluck('das_id')->unique()->values()->all())->pluck('label', 'id')->all();
        $this->users = User::query()->whereKey(array_values(array_filter($list->pluck('responsible_user_id')->unique()->all())))->pluck('name', 'id')->all();
        $this->contracts = AlternanceContract::query()->whereIn('candidature_id', $list->pluck('id'))->get()->keyBy('candidature_id')->all();
        $this->employers = $fiches->organisationLabels(array_values(array_unique(array_map(fn (AlternanceContract $c) => $c->employer_organisation_id, $this->contracts))));
        $this->dossiers = Billing::numbers(array_values(array_filter($list->pluck('dossier_id')->all())));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Candidature $c): array
    {
        $contract = $this->contracts[$c->id] ?? null;
        $dossier = $c->dossier_id !== null ? ($this->dossiers[$c->dossier_id] ?? null) : null;
        $person = $this->people[$c->person_id] ?? null;

        return [
            'id' => $c->id,
            'number' => $c->number,
            'person' => $person['label'] ?? 'Personne anonymisée',
            'last_name' => $person['last_name'] ?? null,
            'first_name' => $person['first_name'] ?? null,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'training' => $c->training_id !== null ? ($this->trainings[$c->training_id] ?? null) : null,
            'rncp_code' => $c->rncp_code,
            'das' => $this->labels[$c->das_id] ?? null,
            'responsible' => $c->responsible_user_id !== null ? ($this->users[$c->responsible_user_id] ?? null) : null,
            'pathway' => $c->pathway?->value,
            'pathway_label' => $c->pathway?->label(),
            'has_contract' => $contract !== null,
            'contract_type' => $contract?->contract_type->label(),
            'employer' => $contract !== null ? ($this->employers[$contract->employer_organisation_id]['label'] ?? null) : null,
            'contract_starts_on' => $contract?->starts_on->toDateString(),
            'contract_ends_on' => $contract?->ends_on->toDateString(),
            'dossier' => $dossier === null || $dossier['trashed'] ? null : $dossier['number'],
            'kind' => $dossier === null || $dossier['trashed'] ? null : CandidaturesBlock::kindOf($contract !== null),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
