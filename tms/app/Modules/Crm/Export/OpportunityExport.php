<?php

namespace App\Modules\Crm\Export;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityRows;
use App\Modules\Crm\Opportunities\OpportunityTraining;
use Illuminate\Database\Eloquent\Model;

final class OpportunityExport implements ExportResource
{
    /** @var array<string, string> titles of the trainings met, by id (one lookup per training) */
    private array $titles = [];

    public function fileName(): string
    {
        return 'opportunites';
    }

    public function sheetName(): string
    {
        return 'Opportunités';
    }

    public function columns(): array
    {
        return [
            'title' => 'Objet',
            'account' => 'Compte',
            'target' => 'Type de compte',
            'das' => 'DAS',
            'training_titles' => 'Formations',
            'stage' => 'Étape',
            'probability' => 'Probabilité (%)',
            'amount' => 'Montant (€)',
            'weighted_amount' => 'Montant pondéré (€)',
            'expected_close_on' => 'Clôture prévue',
            'priority' => 'Priorité',
            'responsible' => 'Responsable',
            'funding_mode' => 'Mode de financement',
            'won_on' => 'Gagnée le',
            'lost_on' => 'Perdue le',
            'loss_reason' => 'Motif de perte',
            'created_at' => 'Création',
        ];
    }

    public function defaults(): array
    {
        return ['title', 'account', 'target', 'das', 'stage', 'probability', 'amount', 'weighted_amount', 'expected_close_on', 'priority', 'responsible'];
    }

    public function with(User $user): array
    {
        return [...OpportunityRows::WITH, 'fundingMode', 'lossReason', 'trainingLines'];
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        assert($model instanceof Opportunity);

        return match ($column) {
            'title' => $model->title,
            'account' => $model->isIndividual() ? $model->person?->fullName() : $model->organisation?->name,
            'target' => $model->isIndividual() ? 'Particulier' : 'Entreprise',
            'das' => $model->das?->label,
            'training_titles' => $this->trainingTitles($model),
            'stage' => $model->stage?->label,
            'probability' => ExportCell::integer($model->probability),
            'amount' => ExportCell::amount($model->amount),
            'weighted_amount' => ExportCell::amount($model->weighted_amount),
            'expected_close_on' => ExportCell::date($model->expected_close_on),
            'priority' => $model->priority?->label,
            'responsible' => $model->responsible?->name,
            'funding_mode' => $model->fundingMode?->label,
            'won_on' => ExportCell::date($model->won_on),
            'lost_on' => ExportCell::date($model->lost_on),
            'loss_reason' => $model->lossReason?->label,
            'created_at' => ExportCell::date($model->created_at),
            default => null,
        };
    }

    /**
     * Titles of its trainings, in order, joined by « ; ».
     */
    private function trainingTitles(Opportunity $opportunity): ?string
    {
        $titles = $opportunity->trainingLines
            ->map(fn (OpportunityTraining $line): string => $this->titles[$line->training_id] ??= app(Trainings::class)->find($line->training_id)->title ?? '')
            ->filter(fn (string $t) => $t !== '')->values()->all();

        return $titles === [] ? null : implode(' ; ', $titles);
    }
}
