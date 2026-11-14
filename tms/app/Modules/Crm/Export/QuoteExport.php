<?php

namespace App\Modules\Crm\Export;

use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteRows;
use Illuminate\Database\Eloquent\Model;

/**
 * Excel export of the « Devis » list: amounts as numbers, dates as dates.
 * No free text nor recipient copy (mentions, refusal reason, e-mail):
 * they may describe a person.
 */
final class QuoteExport implements ExportResource
{
    public function fileName(): string
    {
        return 'devis';
    }

    public function sheetName(): string
    {
        return 'Devis';
    }

    public function columns(): array
    {
        return [
            'number' => 'Numéro',
            'issued_on' => 'Date',
            'valid_until' => 'Valable jusqu\'au',
            'status' => 'Statut',
            'company' => 'Société',
            'account' => 'Compte',
            'target' => 'Type de compte',
            'opportunity' => 'Opportunité',
            'das' => 'DAS',
            'responsible' => 'Responsable',
            'total_ht' => 'Total HT (€)',
            'total_vat' => 'TVA (€)',
            'total_ttc' => 'Total TTC (€)',
            'sent_at' => 'Envoyé le',
            'accepted_on' => 'Accepté le',
            'refused_on' => 'Refusé le',
        ];
    }

    public function defaults(): array
    {
        return ['number', 'issued_on', 'status', 'account', 'opportunity', 'total_ht', 'total_ttc'];
    }

    public function with(User $user): array
    {
        return QuoteRows::WITH;
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        assert($model instanceof Quote);
        $individual = $model->person_id !== null;

        return match ($column) {
            'number' => $model->number,
            'issued_on' => ExportCell::date($model->issued_on),
            'valid_until' => ExportCell::date($model->valid_until),
            'status' => $model->status->label(),
            'company' => $model->company?->name,
            'account' => $individual ? $model->person?->fullName() : $model->organisation?->name,
            'target' => $individual ? 'Particulier' : 'Entreprise',
            'opportunity' => $model->opportunity?->title,
            'das' => $model->opportunity?->das?->label,
            'responsible' => $model->opportunity?->responsible?->name,
            'total_ht' => ExportCell::amount($model->total_ht),
            'total_vat' => ExportCell::amount($model->total_vat),
            'total_ttc' => ExportCell::amount($model->total_ttc),
            'sent_at' => ExportCell::date($model->sent_at),
            'accepted_on' => ExportCell::date($model->accepted_on),
            'refused_on' => ExportCell::date($model->refused_on),
            default => null,
        };
    }
}
