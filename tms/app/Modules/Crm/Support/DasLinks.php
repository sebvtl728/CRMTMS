<?php

namespace App\Modules\Crm\Support;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;

/**
 * DAS of an entreprise or a person (pivot rows carry the tenant), journaled
 * as crm.organisation_das_changed / crm.person_das_changed when they change.
 */
final class DasLinks
{
    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    /**
     * Replaces the DAS (screens).
     *
     * @param  list<string>  $dasIds
     */
    public function sync(Organisation|Person $fiche, array $dasIds): void
    {
        $this->apply($fiche, array_values(array_unique($dasIds)));
    }

    /**
     * Adds DAS, keeping the existing ones (import).
     *
     * @param  list<string>  $dasIds
     */
    public function add(Organisation|Person $fiche, array $dasIds): void
    {
        if ($dasIds === []) {
            return;
        }
        $current = $fiche->exists && ! $fiche->wasRecentlyCreated ? $fiche->das()->pluck('reference_items.id')->all() : [];
        $this->apply($fiche, array_values(array_unique([...$current, ...$dasIds])), $current);
    }

    /**
     * @param  list<string>  $dasIds
     * @param  list<string>|null  $before
     */
    private function apply(Organisation|Person $fiche, array $dasIds, ?array $before = null): void
    {
        $before ??= $fiche->das()->pluck('reference_items.id')->all();
        $tenantId = $this->tenants->id();

        $fiche->das()->sync(collect($dasIds)->mapWithKeys(fn (string $id) => [$id => ['tenant_id' => $tenantId]])->all());

        sort($before);
        $after = $dasIds;
        sort($after);

        if ($before !== $after) {
            $event = $fiche instanceof Organisation ? 'crm.organisation_das_changed' : 'crm.person_das_changed';
            $this->audit->record($event, $fiche, ['das_ids' => $before], ['das_ids' => $after]);
        }
    }
}
