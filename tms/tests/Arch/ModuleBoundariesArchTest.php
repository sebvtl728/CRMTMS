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
    // Trainings (reads, import writes), TrainingData (its result), TrainingUsage (delete guard).
    expect(moduleFilesMatching('Crm', '/^\s*use\s+App\\\\Modules\\\\Catalogue\\\\(?!(Trainings|TrainingData|TrainingUsage);)/m'))->toBe([])
        ->and(moduleFilesMatching('Crm', CATALOGUE_TABLES))->toBe([]);
});

/**
 * A use of the catalogue tables: a query builder call on them, a column
 * they qualify, or SQL naming them. A bare « trainings » (the import target,
 * the form field of an opportunity) names the data, not the table.
 */
const CATALOGUE_TABLES = '/(?<!\w)(?:table|from|join|leftJoin|rightJoin|crossJoin|exists|unique)\(\s*[\'"](?:public\.)?(?:trainings|training_funding_modes)\b'
    .'|[\'"](?:trainings|training_funding_modes)\.'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?(?:trainings|training_funding_modes)\b/i';

it('detects a use of the catalogue tables but not the word', function () {
    foreach (["DB::table('trainings')", "->join('trainings as t', 'x', 'y')", "Rule::exists('trainings', 'id')", "->where('trainings.id', 1)",
        "whereRaw('select 1 from training_funding_modes')", 'insert into public.trainings'] as $code) {
        expect(preg_match(CATALOGUE_TABLES, $code))->toBe(1, $code);
    }
    foreach (["case Trainings = 'trainings';", "\$validated['trainings']", "'opportunity_trainings.training_id'", "DB::table('opportunity_trainings')", "array_key_exists('trainings', \$data)"] as $code) {
        expect(preg_match(CATALOGUE_TABLES, $code))->toBe(0, $code);
    }
});
