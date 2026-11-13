<?php

namespace App\Modules\Crm\Export;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityRows;
use Illuminate\Database\Eloquent\Model;

final class OpportunityExport implements ExportResource
{
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
        return [...OpportunityRows::WITH, 'fundingMode', 'lossReason'];
    }

    public function value(Model $model, string $column): ?string
    {
        assert($model instanceof Opportunity);

        return match ($column) {
            'title' => $model->title,
            'account' => $model->isIndividual() ? $model->person?->fullName() : $model->organisation?->name,
            'target' => $model->isIndividual() ? 'Particulier' : 'Entreprise',
            'das' => $model->das?->label,
            'stage' => $model->stage?->label,
            'probability' => (string) $model->probability,
            'amount' => self::amount($model->amount),
            'weighted_amount' => self::amount($model->weighted_amount),
            'expected_close_on' => $model->expected_close_on?->format('d/m/Y'),
            'priority' => $model->priority?->label,
            'responsible' => $model->responsible?->name,
            'funding_mode' => $model->fundingMode?->label,
            'won_on' => $model->won_on?->format('d/m/Y'),
            'lost_on' => $model->lost_on?->format('d/m/Y'),
            'loss_reason' => $model->lossReason?->label,
            'created_at' => $model->created_at?->format('d/m/Y'),
            default => null,
        };
    }

    /** French amount, « 1 234,50 ». */
    private static function amount(?string $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, ',', ' ');
    }
}
