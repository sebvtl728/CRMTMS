<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Exports\PrefetchesRows;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * CSV export of the « Factures » list: the columns of the list, French dates
 * and amounts, what is paid and left (0.9.1). Names, dossier numbers and
 * balances are read once per chunk. No notes,
 * no lines, no address nor e-mail.
 */
final class InvoiceExport implements ExportResource, PrefetchesRows
{
    /** @var array<string, string> */
    private array $recipients = [];

    /** @var array<string, array{number: string}> */
    private array $dossiers = [];

    /** @var array<string, array<string, mixed>> */
    private array $balances = [];

    public function __construct(private readonly InvoiceRows $rows) {}

    public function fileName(): string
    {
        return 'factures';
    }

    public function sheetName(): string
    {
        return 'Factures';
    }

    public function columns(): array
    {
        return [
            'number' => 'Numéro',
            'kind' => 'Type',
            'issued_on' => 'Date',
            'due_on' => 'Échéance',
            'recipient' => 'Destinataire',
            'dossier' => 'Dossier',
            'total_ht' => 'HT',
            'total_vat' => 'TVA',
            'total_ttc' => 'TTC',
            'status' => 'État',
            'paid' => 'Réglé',
            'remaining' => 'Reste dû',
            'payment_status' => 'État de paiement',
        ];
    }

    public function defaults(): array
    {
        return array_keys($this->columns());
    }

    public function with(User $user): array
    {
        return [];
    }

    public function prefetch(array $models): void
    {
        /** @var Collection<int, Invoice> $invoices */
        $invoices = new Collection($models);
        $this->recipients = $this->rows->recipients($invoices);
        $this->dossiers = Billing::numbers($invoices->pluck('dossier_id')->unique()->values()->all());
        $this->balances = Balances::of($invoices->pluck('id')->all());
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        assert($model instanceof Invoice);

        return match ($column) {
            'number' => $model->number,
            'kind' => $model->kindLabel(),
            'issued_on' => ExportCell::date($model->issued_on),
            'due_on' => ExportCell::date($model->due_on),
            'recipient' => $this->recipients[$model->recipient_type.':'.$model->recipient_id] ?? '—',
            'dossier' => $this->dossiers[$model->dossier_id]['number'] ?? '—',
            'total_ht' => self::amount($model->total_ht_cents),
            'total_vat' => self::amount($model->total_vat_cents),
            'total_ttc' => self::amount($model->total_ttc_cents),
            'status' => $model->status->label(),
            'paid' => isset($this->balances[$model->id]) ? self::amount($this->balances[$model->id]['paid_cents']) : null,
            'remaining' => isset($this->balances[$model->id]) ? self::amount($this->balances[$model->id]['remaining_cents']) : null,
            'payment_status' => isset($this->balances[$model->id])
                ? ($this->balances[$model->id]['overdue'] ? 'En retard' : $this->balances[$model->id]['status']->label())
                : null,
            default => null,
        };
    }

    /** Integer cents as the decimal text ExportCell reads (never through a float). */
    private static function amount(int $cents): ?ExportCell
    {
        $abs = abs($cents);

        return ExportCell::amount(($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT));
    }
}
