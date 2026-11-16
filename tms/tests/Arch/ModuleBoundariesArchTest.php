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
    // Trainings (reads, import writes), TrainingData (its result), TrainingUsage (delete guard), Brochures (brochure library).
    expect(moduleFilesMatching('Crm', '/^\s*use\s+App\\\\Modules\\\\Catalogue\\\\(?!(Trainings|TrainingData|TrainingUsage|Brochures);)/m'))->toBe([])
        ->and(filesNamingCatalogueTables('Crm'))->toBe([]);
});

/**
 * Any mention of a catalogue table: the name as a quoted string (a model's
 * $table, DB::table(), a variable, a quoted SQL identifier), in a string
 * validation rule (exists: / unique:), as the qualifier of a column, or after
 * an SQL keyword. Broad on purpose: Crm names its own data otherwise
 * (training_lines, training_titles, opportunity_trainings).
 */
const CATALOGUE_TABLES = '/[\'"`](?:public\.)?(?:trainings|training_funding_modes|brochures|brochure_versions|brochure_trainings|brochure_das)(?:[\'"`.]|\s+as\b)'
    .'|\b(?:exists|unique):(?:public\.)?(?:trainings|training_funding_modes|brochures|brochure_versions|brochure_trainings|brochure_das)\b'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?["`]?(?:trainings|training_funding_modes|brochures|brochure_versions|brochure_trainings|brochure_das)\b/i';

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
        "array_key_exists('trainings', \$data)", 'Schema::hasTable("training_funding_modes")',
        "DB::table('brochures')", "->join('brochure_versions as v', 'a', 'b')", "'x' => 'exists:brochure_das,brochure_id'", 'select * from brochure_trainings', "'brochures' => []"] as $code) {
        expect(namesCatalogueTables($code))->toBeTrue($code);
    }
    foreach (["    case Trainings = 'trainings';", "'opportunity_trainings.training_id'", "DB::table('opportunity_trainings')",
        "'training_lines' => []", "'training_titles' => 'Formations'", "DB::table('quote_brochures')", '// the trainings of the catalogue'] as $code) {
        expect(namesCatalogueTables($code))->toBeFalse($code);
    }
});

// ---------------------------------------------------------------- Sessions (lot 2a)

/**
 * Any mention of a CRM table, same shapes as CATALOGUE_TABLES: Sessions
 * reaches the CRM through Crm\Public only. « notes » is left out: it is also
 * a column of the dossiers, sessions and enrolments.
 */
const CRM_TABLES_LIST = 'organisations|organisation_das|people|person_das|affiliations|opportunities|opportunity_stage_changes|opportunity_trainings'
    .'|opportunity_contacts|quotes|quote_lines|quote_sequences|activities|attachments|consents|fiche_trainings|gdpr_pending|import_batches|import_rows|import_stamps|import_profiles'
    .'|saved_filters|recent_views|revenue_targets|user_das';

const CRM_TABLES = '/[\'"`](?:public\.)?(?:'.CRM_TABLES_LIST.')(?:[\'"`.]|\s+as\b)'
    .'|\b(?:exists|unique):(?:public\.)?(?:'.CRM_TABLES_LIST.')\b'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?["`]?(?:'.CRM_TABLES_LIST.')\b/i';

function namesCrmTables(string $code): bool
{
    foreach (preg_split('/\R/', $code) ?: [] as $line) {
        if (preg_match(CRM_TABLES, $line) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * A reference to the Crm other than its Public namespace: a use line, a
 * grouped use (`use App\Modules\Crm\{...}`, `use App\Modules\{Crm\...}`) or a
 * fully qualified name inline (`\App\Modules\Crm\People\Person::class`).
 */
const SESSIONS_CRM_REFERENCE = '/App\\\\Modules\\\\Crm\\\\(?!Public\b)|App\\\\Modules\\\\\{[^}]*\bCrm\\\\(?!Public\b)/';

/** Same for the catalogue: only Trainings, TrainingData and TrainingUsage. */
const SESSIONS_CATALOGUE_REFERENCE = '/App\\\\Modules\\\\Catalogue\\\\(?!(?:Trainings|TrainingData|TrainingUsage)\b)|App\\\\Modules\\\\\{[^}]*\bCatalogue\\\\(?!(?:Trainings|TrainingData|TrainingUsage)\b)/';

it('lets Sessions reach the CRM and the catalogue through their public services only', function () {
    expect(moduleFilesMatching('Sessions', SESSIONS_CRM_REFERENCE))->toBe([])
        ->and(moduleFilesMatching('Sessions', SESSIONS_CATALOGUE_REFERENCE))->toBe([]);

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Sessions'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (namesCrmTables($code) || namesCatalogueTables($code)) {
            $offenders[] = $file->getPathname();
        }
    }
    expect($offenders)->toBe([]);
});

it('detects every way of reaching the Crm or the catalogue beyond their public services', function () {
    foreach ([
        'use App\Modules\Crm\People\Person;',
        'use App\Modules\Crm\{Public\Trainees, People\Person};',
        'use App\Modules\{Crm\People\Person, Core\Access\Role};',
        '$p = new \App\Modules\Crm\People\Person();',
        'Person::class === \App\Modules\Crm\Organisations\Organisation::class',
    ] as $code) {
        expect(preg_match(SESSIONS_CRM_REFERENCE, $code), $code)->toBe(1);
    }
    foreach ([
        'use App\Modules\Crm\Public\Trainees;',
        'use App\Modules\Crm\Public\{Trainees, Fiches};',
        'app(\App\Modules\Crm\Public\Fiches::class)',
        'use App\Modules\Core\References\ReferenceList;',
    ] as $code) {
        expect(preg_match(SESSIONS_CRM_REFERENCE, $code), $code)->toBe(0);
    }

    foreach ([
        'use App\Modules\Catalogue\Training;',
        'use App\Modules\Catalogue\{Trainings, Training};',
        'use App\Modules\{Catalogue\Training, Crm\Public\Fiches};',
        '$t = \App\Modules\Catalogue\Brochures\Brochures::class;',
    ] as $code) {
        expect(preg_match(SESSIONS_CATALOGUE_REFERENCE, $code), $code)->toBe(1);
    }
    foreach ([
        'use App\Modules\Catalogue\Trainings;',
        'use App\Modules\Catalogue\TrainingData;',
        'use App\Modules\Catalogue\TrainingUsage;',
        'app(\App\Modules\Catalogue\Trainings::class)',
    ] as $code) {
        expect(preg_match(SESSIONS_CATALOGUE_REFERENCE, $code), $code)->toBe(0);
    }
});

it('keeps Crm, Catalogue and Core free of any import of the Sessions module', function () {
    foreach (['Crm', 'Catalogue', 'Core', 'Connectors'] as $module) {
        expect(moduleFilesMatching($module, '/^\s*use\s+App\\\\Modules\\\\Sessions\\\\/m'), $module)->toBe([]);
    }
});

it('detects a use of the CRM tables', function () {
    foreach (["DB::table('people')", "->join('organisations as o', 'x', 'y')", "Rule::exists('people', 'id')", "->where('quotes.status', 'accepted')",
        'select 1 from quote_lines', "'person_id' => 'exists:people,id'", "->leftJoin('affiliations', 'a', 'b')", 'update opportunities set'] as $code) {
        expect(namesCrmTables($code))->toBeTrue($code);
    }
    foreach (["'session_people'", "DB::table('dossier_lines')", "'quote_id' => \$id", "'organisation_id'", '// the people of the session',
        "'enrolments'", "DB::table('training_sessions')", "'notes' => \$data['notes']"] as $code) {
        expect(namesCrmTables($code))->toBeFalse($code);
    }
});
