<?php

it('keeps Core free of any import of the Crm module', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Core'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match('/^\s*use\s+App\\\\Modules\\\\Crm\\\\/m', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
