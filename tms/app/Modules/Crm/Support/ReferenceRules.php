<?php

namespace App\Modules\Crm\Support;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Validation rules shared by the CRM requests. A value that is already stored
 * on the fiche stays valid even when it was deactivated since (the form keeps
 * showing it); a deactivated item can never be newly assigned.
 */
final class ReferenceRules
{
    /**
     * An active item of $list in the current organisme, or one of $keep.
     *
     * Returned as a rule list: a malformed id is rejected before the query
     * (PostgreSQL would raise on the uuid cast).
     *
     * @param  string|list<string>|null  $keep  ids already stored on the fiche
     * @return list<mixed>
     */
    public static function item(ReferenceList $list, string|array|null $keep = null): array
    {
        $keep = array_values(array_filter((array) $keep));

        return ['bail', 'uuid', Rule::exists('reference_items', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('list', $list->value)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->when($keep !== [], fn ($q2) => $q2->orWhereIn('id', $keep)))];
    }

    /**
     * An active user of the organisme; a deactivated one only if he already
     * is the responsible of the fiche (he may stay until changed).
     *
     * @return list<mixed>
     */
    public static function responsible(?string $keep = null): array
    {
        return ['bail', 'uuid', Rule::exists('users', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where(fn ($q) => $q->whereNull('deactivated_at')->when($keep, fn ($q2, $id) => $q2->orWhere('id', $id)))];
    }
}
