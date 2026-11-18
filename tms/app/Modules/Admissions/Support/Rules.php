<?php

namespace App\Modules\Admissions\Support;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Validation rules on Core tables (referentials, users) for the Admissions
 * module: an active item or the value already stored; an active user of the
 * organisme or the one already stored.
 */
final class Rules
{
    /**
     * @param  string|list<string>|null  $keep
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
     * @return list<mixed>
     */
    public static function user(?string $keep = null): array
    {
        return ['bail', 'uuid', Rule::exists('users', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where(fn ($q) => $q->whereNull('deactivated_at')->when($keep !== null, fn ($q2) => $q2->orWhere('id', $keep)))];
    }
}
