<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Exports\PrefetchesRows;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Export of the candidature list (CSV « ; »): the columns of the list, never
 * a sensitive value.
 */
final class CandidatureExport implements ExportResource, PrefetchesRows
{
    private CandidatureRows $rows;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct()
    {
        $this->rows = new CandidatureRows;
    }

    public function fileName(): string
    {
        return 'candidatures';
    }

    public function sheetName(): string
    {
        return 'Candidatures';
    }

    public function columns(): array
    {
        return [
            'number' => 'Numéro', 'last_name' => 'Nom', 'first_name' => 'Prénom', 'status' => 'Étape', 'training' => 'Formation',
            'rncp_code' => 'Code RNCP', 'das' => 'DAS', 'responsible' => 'Responsable', 'contract_type' => 'Type de contrat',
            'employer' => 'Employeur', 'contract_starts_on' => 'Début contrat', 'contract_ends_on' => 'Fin contrat', 'dossier' => 'Dossier',
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
        /** @var list<Candidature> $models */
        $this->rows->prefetch($models);
        $this->cache = [];
        foreach ($models as $model) {
            $this->cache[$model->id] = $this->rows->row($model);
        }
    }

    public function value(Model $model, string $column): string|ExportCell|null
    {
        $row = $this->cache[(string) $model->getKey()] ?? null;
        if ($row === null) {
            return null;
        }

        return match ($column) {
            'status' => $row['status_label'],
            'contract_starts_on', 'contract_ends_on' => $row[$column] === null ? null : ExportCell::date(CarbonImmutable::parse($row[$column])),
            default => $row[$column] === null ? null : (string) $row[$column],
        };
    }
}
