<?php

namespace App\Modules\Crm\Settings;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;

/**
 * Follow-up and dashboard settings of the organisme (lot 1e), in
 * tenants.settings['crm']: the reactivation delays after a dossier (months,
 * default 6 and 12, none = disabled), the pipeline stage from which a fiche
 * counts as a lead (default: the second stage, « Contacté ») and the
 * sliding window of the retention rate (months, default 24).
 */
final class CrmFollowUpSettings
{
    public const DEFAULT_REACTIVATION = [6, 12];

    public const DEFAULT_RETENTION = 24;

    public const MAX_REACTIVATION = 36;

    public const MAX_RETENTION = 120;

    /** @return list<int> sorted, distinct */
    public static function reactivationMonths(?Tenant $tenant = null): array
    {
        $value = data_get(self::settings($tenant), 'crm.reactivation_months');
        if (! is_array($value)) {
            return self::DEFAULT_REACTIVATION;
        }

        return self::normaliseMonths($value);
    }

    /** The lead threshold stage (a live pipeline stage), else the second stage. */
    public static function leadStageId(?Tenant $tenant = null): ?string
    {
        $id = data_get(self::settings($tenant), 'crm.lead_stage_id');
        $stages = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->orderBy('position');
        if (is_string($id) && (clone $stages)->whereKey($id)->exists()) {
            return $id;
        }

        return $stages->skip(1)->value('id');
    }

    public static function retentionMonths(?Tenant $tenant = null): int
    {
        $value = data_get(self::settings($tenant), 'crm.retention_months');

        return is_int($value) && $value >= 1 && $value <= self::MAX_RETENTION ? $value : self::DEFAULT_RETENTION;
    }

    /**
     * @param  array<array-key, mixed>  $months
     * @return list<int>
     */
    public static function normaliseMonths(array $months): array
    {
        $out = array_values(array_unique(array_filter(array_map('intval', $months), fn (int $m) => $m >= 1 && $m <= self::MAX_REACTIVATION)));
        sort($out);

        return $out;
    }

    /**
     * The stored settings, read again (the tenant of the context is kept in
     * memory for the whole request or job).
     *
     * @return array<string, mixed>
     */
    private static function settings(?Tenant $tenant): array
    {
        $id = $tenant->id ?? app(TenantContext::class)->id();

        return Tenant::query()->whereKey($id)->first()->settings ?? [];
    }
}
