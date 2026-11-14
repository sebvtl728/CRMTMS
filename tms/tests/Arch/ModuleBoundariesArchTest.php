<?php

/**
 * PHP files of app/Modules/<module> whose content matches $pattern.
 *
 * @return list<string>
 */
function moduleFilesMatching(string $module, string $pattern): array
{
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/'.$module))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    return $offenders;
}

it('keeps Core free of any import of the Crm module', function () {
    expect(moduleFilesMatching('Core', '/^\s*use\s+App\\\\Modules\\\\Crm\\\\/m'))->toBe([]);
});

it('keeps Core free of any import of the Catalogue module', function () {
    expect(moduleFilesMatching('Core', '/^\s*use\s+App\\\\Modules\\\\Catalogue\\\\/m'))->toBe([]);
});

it('keeps Catalogue free of any import of the Crm module', function () {
    expect(moduleFilesMatching('Catalogue', '/^\s*use\s+App\\\\Modules\\\\Crm\\\\/m'))->toBe([]);
});

it('lets Crm reach the catalogue through its public service only', function () {
    // Trainings (reads), TrainingData (its result), TrainingUsage (delete guard).
    expect(moduleFilesMatching('Crm', '/^\s*use\s+App\\\\Modules\\\\Catalogue\\\\(?!(Trainings|TrainingData|TrainingUsage);)/m'))->toBe([])
        ->and(moduleFilesMatching('Crm', '/[\'"](trainings|training_funding_modes)[\'"]/'))->toBe([]);
});
