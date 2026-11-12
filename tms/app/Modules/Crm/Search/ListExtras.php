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
     * @return array{savedFilters: list<array<string, mixed>>, columns: array{options: list<array{value: string, label: string}>, defaults: list<string>}}
     */
    public static function for(CrmResource $resource, User $user): array
    {
        $export = $resource->export();

        return [
            'savedFilters' => SavedFilter::visibleTo($user)->where('resource', $resource->value)->with('author')->orderBy('name')->get()
                ->map(fn (SavedFilter $f) => $f->present($user))->all(),
            'columns' => [
                'options' => array_map(fn (string $key, string $label) => ['value' => $key, 'label' => $label], array_keys($export->columns()), array_values($export->columns())),
                'defaults' => $export->defaults(),
            ],
        ];
    }
}
