<?php

namespace App\Modules\Crm\Support;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;

/**
 * Select options of the CRM screens. The current value of a fiche stays
 * listed (and labelled) when it is no longer offered: an inactive reference
 * item, or a deactivated responsible.
 */
final class FormOptions
{
    /**
     * Active items, plus the given current ones even when inactive.
     *
     * @param  list<string>  $currentIds
     * @return list<array{value: string, label: string}>
     */
    public static function reference(ReferenceList $list, ?string $currentId = null, array $currentIds = []): array
    {
        $keep = array_filter([...$currentIds, $currentId]);

        return ReferenceItem::query()->where('list', $list)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep))
            ->orderBy('position')->orderBy('label')->get()
            ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->is_active ? $i->label : $i->label.' (inactif)'])
            ->values()->all();
    }

    /**
     * Active users, plus the current responsible when deactivated.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function responsible(?string $currentId = null): array
    {
        $responsible = User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all();

        if ($currentId !== null && ! in_array($currentId, array_column($responsible, 'value'), true)) {
            $user = User::query()->find($currentId);
            if ($user !== null) {
                $responsible[] = ['value' => $user->id, 'label' => $user->name.' (désactivé)'];
            }
        }

        return $responsible;
    }
}
