<?php

namespace App\Modules\Crm\Search;

use App\Modules\Core\Users\User;

/**
 * Props the two list pages share: the saved filters visible to the user and
 * the columns that can be exported.
 */
final class ListExtras
{
    /**
     * @return array{savedFilters: list<array<string, mixed>>, activeSavedId: ?string, columns: array{options: list<array{value: string, label: string}>, defaults: list<string>}}
     */
    public static function for(CrmResource $resource, User $user, mixed $activeSaved = null): array
    {
        $export = $resource->export();
        $saved = SavedFilter::visibleTo($user)->where('resource', $resource->value)->with('author')->orderBy('name')->get();

        return [
            'savedFilters' => $saved->map(fn (SavedFilter $f) => $f->present($user))->all(),
            // The saved filter the list was opened with (?saved=id), if still visible.
            'activeSavedId' => is_string($activeSaved) ? $saved->firstWhere('id', $activeSaved)?->id : null,
            'columns' => [
                'options' => array_map(fn (string $key, string $label) => ['value' => $key, 'label' => $label], array_keys($export->columns()), array_values($export->columns())),
                'defaults' => $export->defaults(),
            ],
        ];
    }
}
