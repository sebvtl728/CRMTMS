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
        ->and(filesNamingCatalogueTables('Crm'))->toBe([]);
});

/**
 * Any mention of a catalogue table: the name as a quoted string (a model's
 * $table, DB::table(), a variable, a quoted SQL identifier), in a string
 * validation rule (exists: / unique:), as the qualifier of a column, or after
 * an SQL keyword. Broad on purpose: Crm names its own data otherwise
 * (training_lines, training_titles, opportunity_trainings).
 */
const CATALOGUE_TABLES = '/[\'"`](?:public\.)?(?:trainings|training_funding_modes)(?:[\'"`.]|\s+as\b)'
    .'|\b(?:exists|unique):(?:public\.)?(?:trainings|training_funding_modes)\b'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?["`]?(?:trainings|training_funding_modes)\b/i';

/**
 * Lines allowed to name « trainings » although they match, each for a reason.
 *
 * @var list<string>
 */
const CATALOGUE_TABLES_EXEMPT = [
    // The import target « Catalogue de formations » (ImportTarget::Trainings): a value stored in import_batches.target, not the table.
    '/^\s*case Trainings = \'trainings\';$/',
];

function namesCatalogueTables(string $code): bool
{
    foreach (preg_split('/\R/', $code) ?: [] as $line) {
        foreach (CATALOGUE_TABLES_EXEMPT as $exempt) {
            if (preg_match($exempt, $line) === 1) {
                continue 2;
            }
        }
        if (preg_match(CATALOGUE_TABLES, $line) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function filesNamingCatalogueTables(string $module): array
{
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/'.$module))) as $file) {
        if ($file->getExtension() === 'php' && namesCatalogueTables((string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getPathname();
        }
    }

    return $offenders;
}

it('detects a use of the catalogue tables, the import target line apart', function () {
    foreach (["DB::table('trainings')", "->join('trainings as t', 'x', 'y')", "Rule::exists('trainings', 'id')", "->where('trainings.id', 1)",
        "whereRaw('select 1 from training_funding_modes')", 'insert into public.trainings', "protected \$table = 'trainings';",
        'DB::select(\'select * from "trainings"\')', "\$t = 'trainings'; DB::table(\$t);", "'das_id' => 'exists:trainings,id'",
        "'code' => 'unique:trainings'", "'x' => ['exists:public.training_funding_modes,training_id']", "\$validated['trainings']",
        "array_key_exists('trainings', \$data)", 'Schema::hasTable("training_funding_modes")'] as $code) {
        expect(namesCatalogueTables($code))->toBeTrue($code);
    }
    foreach (["    case Trainings = 'trainings';", "'opportunity_trainings.training_id'", "DB::table('opportunity_trainings')",
        "'training_lines' => []", "'training_titles' => 'Formations'", '// the trainings of the catalogue'] as $code) {
        expect(namesCatalogueTables($code))->toBeFalse($code);
    }
});
