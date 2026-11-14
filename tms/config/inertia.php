<?php

/*
| Only the keys that differ from the package defaults (merged at the top level):
| the pages live in resources/js/pages (lowercase). The package default
| (js/Pages) only works on a case-insensitive file system such as macOS.
*/

return [

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/pages'),
        ],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
