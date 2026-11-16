<?php

namespace App\Modules\Connectors\Core;

/**
 * Optional capability: how the generic configuration form shows some
 * settings (a list of choices, a checkbox with a warning, suggestions, a
 * field shown only for one value of another setting, a field not offered
 * at all: hidden, neither shown nor submitted). Settings without an
 * entry are plain text fields.
 */
interface HasFormFields
{
    /**
     * @return array<string, array{
     *     type: 'select'|'checkbox'|'text',
     *     options?: list<array{value: string, label: string}>,
     *     default?: string|bool,
     *     suggestions?: list<string>,
     *     help?: string,
     *     warning?: string,
     *     hidden?: bool,
     *     visible_when?: array<string, list<string>>
     * }>
     */
    public function fields(): array;
}
