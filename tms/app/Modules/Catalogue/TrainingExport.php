<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Excel export of the catalogue; its cells also feed the list (as French
 * text through ExportCell::display), so both always agree.
 */
final class TrainingExport implements ExportResource
{
    public function fileName(): string
    {
        return 'catalogue';
    }

    public function sheetName(): string
    {
        return 'Catalogue';
    }

    public function columns(): array
    {
        return [
            'code' => 'Code',
            'title' => 'Intitulé',
            'das' => 'DAS',
            'duration_hours' => 'Durée (heures)',
            'duration_days' => 'Durée (jours)',
            'centre_hours' => 'Heures en centre',
            'company_hours' => 'Heures en entreprise',
            'price_company' => 'Tarif entreprise HT (€)',
            'price_individual' => 'Tarif particulier HT (€)',
            'funding_modes' => 'Financements',
            'is_active' => 'Active',
            'audience' => 'Public visé',
            'prerequisites' => 'Prérequis',
            'description' => 'Présentation',
            'objectives' => 'Objectifs pédagogiques',
            'methods' => 'Méthodes et moyens',
            'evaluation' => 'Évaluation',
            'sanction' => 'Sanction de la formation',
            'accessibility' => 'Accessibilité',
            'access_terms' => 'Modalités et délais d\'accès',
            'results' => 'Résultats',
            'bpf_objective' => 'Objectif BPF',
            'nsf_code' => 'Code NSF',
        ];
    }

    public function defaults(): array
    {
        return ['code', 'title', 'das', 'duration_hours', 'duration_days', 'price_company', 'price_individual', 'funding_modes', 'is_active'];
    }

    public function with(User $user): array
    {
        return self::relations();
    }

    /**
     * Relations of a row (list and export): funding modes in the order of
     * their référentiel.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return ['das', 'fundingModes' => fn (BelongsToMany $q) => $q->orderBy('reference_items.position')->orderBy('reference_items.label')];
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        assert($model instanceof Training);

        return match ($column) {
            'code' => $model->code,
            'title' => $model->title,
            'das' => $model->das?->label,
            'duration_hours' => ExportCell::decimal($model->duration_hours),
            'duration_days' => ExportCell::decimal($model->duration_days),
            'centre_hours' => ExportCell::decimal($model->centre_hours),
            'company_hours' => ExportCell::decimal($model->company_hours),
            'price_company' => ExportCell::amount($model->price_company),
            'price_individual' => ExportCell::amount($model->price_individual),
            'funding_modes' => $model->fundingModes->pluck('label')->implode(', '),
            'is_active' => $model->is_active ? 'Oui' : 'Non',
            'audience' => $model->audience,
            'prerequisites' => $model->prerequisites,
            'description' => $model->description,
            'objectives' => $model->objectives === null ? null : implode("\n", $model->objectives),
            'methods' => $model->methods,
            'evaluation' => $model->evaluation,
            'sanction' => $model->sanction,
            'accessibility' => $model->accessibility,
            'access_terms' => $model->access_terms,
            'results' => $model->results,
            'bpf_objective' => $model->bpf_objective?->label(),
            'nsf_code' => Nsf::display($model->nsf_code),
            default => null,
        };
    }
}
