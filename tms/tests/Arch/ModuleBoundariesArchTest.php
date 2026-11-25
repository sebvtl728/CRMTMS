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

it('lets Catalogue reach the connectors through the public AI service only', function () {
    // Ai (configured, structured) and its two exceptions (fixed French messages).
    expect(moduleFilesMatching('Catalogue', '/App\\\\Modules\\\\Connectors\\\\(?!Ai\\\\(?:Ai|AiUnavailable|AiInvalidResponse)\b|OpenData\\\\RncpExport\b)/'))->toBe([]);
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

/** Same for the catalogue: only Trainings, TrainingData, TrainingUsage and the TrainingBlocks registry. */
const SESSIONS_CATALOGUE_REFERENCE = '/App\\\\Modules\\\\Catalogue\\\\(?!(?:Trainings|TrainingData|TrainingUsage|TrainingBlocks)\b)|App\\\\Modules\\\\\{[^}]*\bCatalogue\\\\(?!(?:Trainings|TrainingData|TrainingUsage|TrainingBlocks)\b)/';

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
        'use App\Modules\Catalogue\TrainingBlocks;',
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

// ---------------------------------------------------------------- Finance (0.9)

it('keeps Sessions, Crm, Catalogue, Core and Connectors free of any reference to the Finance module', function () {
    foreach (['Sessions', 'Crm', 'Catalogue', 'Core', 'Connectors'] as $module) {
        expect(moduleFilesMatching($module, '/App\\\\Modules\\\\Finance\\\\|App\\\\Modules\\\\\{[^}]*\bFinance\\\\/'), $module)->toBe([]);
    }
});

/**
 * Any mention of a Sessions table, same shapes as CRM_TABLES: Finance reads
 * the dossiers through Sessions\Public only.
 */
const SESSIONS_TABLES_LIST = 'training_sessions|session_sequences|dossiers|dossier_lines|dossier_sequences|enrolments|fundings|session_modules|seances'
    .'|attendances|monthly_hours|attendance_closures|attendance_sheet_scans|placement_periods|placement_period_hosts|rooms|closure_periods'
    .'|trainers|trainer_das|trainer_unavailabilities|session_tasks|session_notes';

const SESSIONS_TABLES = '/[\'"`](?:public\.)?(?:'.SESSIONS_TABLES_LIST.')(?:[\'"`.]|\s+as\b)'
    .'|\b(?:exists|unique):(?:public\.)?(?:'.SESSIONS_TABLES_LIST.')\b'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?["`]?(?:'.SESSIONS_TABLES_LIST.')\b/i';

/**
 * The keys « enrolments » and « fundings » of the arrays of
 * Sessions\Public\Billing (a subscript or an array key), not tables: left
 * out before looking for a Sessions table in Finance.
 */
const SESSIONS_BILLING_KEYS = "/\\[\\s*'(?:enrolments|fundings)'\\s*\\]|'(?:enrolments|fundings)'\\s*=>/";

function namesSessionsTables(string $code): bool
{
    return preg_match(SESSIONS_TABLES, (string) preg_replace(SESSIONS_BILLING_KEYS, '', $code)) === 1;
}

/**
 * A reference to another module than Finance and Core, beyond their public
 * services: Sessions\Public, Crm\Public and the catalogue's Trainings,
 * TrainingData and TrainingUsage, and the four connector classes the e-mailing
 * of an invoice needs (Smtp\TenantMailer, Smtp\SmtpHostRefused,
 * Core\ConnectorRun, Core\PermanentDeliveryFailure) (a use line, a grouped use
 * or an inline fully qualified name).
 */
const FINANCE_FOREIGN_REFERENCE = '/App\\\\Modules\\\\(?!(?:Finance|Core)\b|Sessions\\\\Public\b|Crm\\\\Public\b|Catalogue\\\\(?:Trainings|TrainingData|TrainingUsage)\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure))\b)\w+'
    .'|App\\\\Modules\\\\\{[^}]*\b(?!(?:Finance|Core)\\\\|Sessions\\\\Public\b|Crm\\\\Public\b|Catalogue\\\\(?:Trainings|TrainingData|TrainingUsage)\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure))\b)(?:Sessions|Crm|Catalogue|Connectors)\\\\/';

it('lets Finance reach the other modules through their public services only, never their tables', function () {
    expect(moduleFilesMatching('Finance', FINANCE_FOREIGN_REFERENCE))->toBe([]);

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Finance'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (namesCrmTables($code) || namesCatalogueTables($code) || namesSessionsTables($code)) {
            $offenders[] = $file->getPathname();
        }
    }
    expect($offenders)->toBe([]);
});

it('detects every way Finance could reach Sessions, the Crm, the catalogue or the connectors beyond their public services', function () {
    foreach ([
        'use App\Modules\Sessions\Dossiers\Dossier;',
        'use App\Modules\Sessions\Fundings\FundingWriter;',
        'use App\Modules\Crm\People\Person;',
        'use App\Modules\Catalogue\Training;',
        'use App\Modules\Connectors\Smtp\SmtpTransportFactory;',
        'use App\Modules\Connectors\Core\ConnectorConfig;',
        'use App\Modules\Connectors\Mail365\GraphMailClient;',
        'use App\Modules\{Sessions\Dossiers\Dossier, Core\Access\Role};',
        '$d = \App\Modules\Sessions\SessionsVisibility::class;',
    ] as $code) {
        expect(preg_match(FINANCE_FOREIGN_REFERENCE, $code), $code)->toBe(1);
    }
    foreach ([
        'use App\Modules\Sessions\Public\Billing;',
        'use App\Modules\Sessions\Public\{Billing, DossierBlocks};',
        'use App\Modules\Crm\Public\Fiches;',
        'use App\Modules\Catalogue\Trainings;',
        'use App\Modules\Core\Access\Permissions;',
        'use App\Modules\Connectors\Smtp\TenantMailer;',
        'use App\Modules\Connectors\Smtp\SmtpHostRefused;',
        'use App\Modules\Connectors\Core\ConnectorRun;',
        'use App\Modules\Connectors\Core\PermanentDeliveryFailure;',
        'use App\Modules\Finance\Plans\BillingPlans;',
        'namespace App\Modules\Finance;',
        'use App\Modules\{Core\Access\Role, Sessions\Public\Billing};',
    ] as $code) {
        expect(preg_match(FINANCE_FOREIGN_REFERENCE, $code), $code)->toBe(0);
    }

    foreach (["DB::table('dossiers')", "->join('enrolments as e', 'a', 'b')", "Rule::exists('fundings', 'id')", 'select 1 from seances',
        "'dossier_id' => 'exists:dossiers,id'", "->where('training_sessions.id', 1)"] as $code) {
        expect(preg_match(SESSIONS_TABLES, $code), $code)->toBe(1);
    }
    foreach (["'dossier_id'", "DB::table('billing_plans')", "'billing_plans.dossier_id'", '// the dossiers of the organisme', "route('finance.plans.update')"] as $code) {
        expect(preg_match(SESSIONS_TABLES, $code), $code)->toBe(0);
    }
    // The keys of the arrays of Sessions\Public\Billing are no tables; a table next to them still is.
    foreach (['$dossier[\'fundings\']', '$hours[ \'enrolments\' ]', '[\'enrolments\' => [], \'sessions\' => []]'] as $code) {
        expect(namesSessionsTables($code), $code)->toBeFalse();
    }
    foreach (['$dossier[\'fundings\']; DB::table(\'enrolments\')', "->join('fundings as f', 'a', 'b')"] as $code) {
        expect(namesSessionsTables($code), $code)->toBeTrue();
    }
});

// ---------------------------------------------------------------- Admissions

/**
 * A reference to another module than Admissions and Core beyond their public
 * services: Crm\Public, Sessions\Public, the catalogue's Trainings,
 * TrainingData, TrainingUsage and Brochures, and the mailer pieces of
 * Connectors (as Finance) (a use line, a grouped use or an inline
 * fully qualified name).
 */
const ADMISSIONS_FOREIGN_REFERENCE = '/App\\\\Modules\\\\(?!(?:Admissions|Core)\b|Sessions\\\\Public\b|Crm\\\\Public\b|Qualite\\\\Public\b|Catalogue\\\\(?:Trainings|TrainingData|TrainingUsage|Brochures|Assistant\\\\PdfText)\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure)|Ai\\\\(?:Ai|AiUnavailable|AiInvalidResponse))\b)\w+'
    .'|App\\\\Modules\\\\\{[^}]*\b(?!(?:Admissions|Core)\\\\|Sessions\\\\Public\b|Crm\\\\Public\b|Catalogue\\\\(?:Trainings|TrainingData|TrainingUsage|Brochures|Assistant\\\\PdfText)\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure)|Ai\\\\(?:Ai|AiUnavailable|AiInvalidResponse))\b)(?:Sessions|Crm|Catalogue|Connectors|Finance)\\\\/';

const ADMISSIONS_TABLES_LIST = 'candidatures|candidature_sequences|candidature_files|alternance_contracts|custom_field_definitions|positioning_sendings|positioning_sending_versions|online_tests|online_test_versions|online_test_das|online_test_trainings|online_test_attempts|booklet_months|booklet_links|booklet_evaluations|booklet_visits';

const ADMISSIONS_TABLES = '/[\'"`](?:public\.)?(?:'.ADMISSIONS_TABLES_LIST.')(?:[\'"`.]|\s+as\b)'
    .'|\b(?:exists|unique):(?:public\.)?(?:'.ADMISSIONS_TABLES_LIST.')\b'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?["`]?(?:'.ADMISSIONS_TABLES_LIST.')\b/i';

it('keeps every other module free of any reference to the Admissions module or its tables', function () {
    foreach (['Crm', 'Catalogue', 'Core', 'Connectors', 'Sessions', 'Finance'] as $module) {
        expect(moduleFilesMatching($module, '/App\\\\Modules\\\\Admissions\\\\|App\\\\Modules\\\\\{[^}]*\bAdmissions\\\\/'), $module)->toBe([])
            // The local sandbox reset names every table it keeps, by design (Core\Platform\Sandbox::KEPT).
            ->and(array_values(array_filter(moduleFilesMatching($module, ADMISSIONS_TABLES), fn (string $f) => ! str_ends_with($f, 'Core/Platform/Sandbox.php'))), $module)->toBe([]);
    }
});

it('lets Admissions reach the other modules through their public services only, never their tables', function () {
    expect(moduleFilesMatching('Admissions', ADMISSIONS_FOREIGN_REFERENCE))->toBe([]);

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Admissions'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (namesCrmTables($code) || namesCatalogueTables($code) || namesSessionsTables($code)) {
            $offenders[] = $file->getPathname();
        }
    }
    expect($offenders)->toBe([]);
});

it('detects every way Admissions could reach another module beyond its public services', function () {
    foreach ([
        'use App\Modules\Crm\People\Person;',
        'use App\Modules\Sessions\Dossiers\DossierWriter;',
        'use App\Modules\Catalogue\Training;',
        'use App\Modules\Finance\Invoices\Invoice;',
        'use App\Modules\Connectors\Smtp\SmtpConnector;',
        'use App\Modules\{Crm\People\Person, Core\Access\Role};',
        '$x = \App\Modules\Sessions\SessionsVisibility::class;',
    ] as $code) {
        expect(preg_match(ADMISSIONS_FOREIGN_REFERENCE, $code), $code)->toBe(1);
    }
    foreach ([
        'use App\Modules\Crm\Public\Fiches;',
        'use App\Modules\Sessions\Public\Admission;',
        'use App\Modules\Qualite\Public\Measures;',
        'use App\Modules\Catalogue\TrainingData;',
        'use App\Modules\Core\Tenancy\TenantStorage;',
        'use App\Modules\Admissions\Candidatures\Candidature;',
        'use App\Modules\{Core\Access\Role, Crm\Public\Fiches};',
    ] as $code) {
        expect(preg_match(ADMISSIONS_FOREIGN_REFERENCE, $code), $code)->toBe(0);
    }
    foreach (["DB::table('candidatures')", "->join('alternance_contracts as a', 'x', 'y')", 'select 1 from custom_field_definitions', "'x' => 'exists:candidature_files,id'"] as $code) {
        expect(preg_match(ADMISSIONS_TABLES, $code), $code)->toBe(1);
    }
    foreach (["'candidature_id'", "'admissions.candidatures.view'", '// the candidatures of a person'] as $code) {
        expect(preg_match(ADMISSIONS_TABLES, $code), $code)->toBe(0);
    }
});

it('keeps the Qualité module apart: it reads no other module, they declare their measures through Qualite\\Public', function () {
    expect(moduleFilesMatching('Qualite', '/App\\\\Modules\\\\(?!(?:Qualite|Core)\\b)\\w+/'))->toBe([]);
});

// ---------------------------------------------------------------- Documents (0.10)

it('keeps every other module free of any reference to the Documents module', function () {
    foreach (['Sessions', 'Crm', 'Catalogue', 'Core', 'Connectors', 'Finance', 'Admissions', 'Qualite'] as $module) {
        expect(moduleFilesMatching($module, '/App\\\\Modules\\\\Documents\\\\|App\\\\Modules\\\\\{[^}]*\bDocuments\\\\/'), $module)->toBe([]);
    }
});

/**
 * A reference to another module than Documents and Core beyond their public
 * services: the same openings as Finance (Sessions\Public, Crm\Public, the
 * catalogue's Trainings / TrainingData / TrainingUsage, the four connector
 * classes an e-mail needs).
 */
const DOCUMENTS_FOREIGN_REFERENCE = '/App\\\\Modules\\\\(?!(?:Documents|Core)\b|Sessions\\\\Public\b|Crm\\\\Public\b|Catalogue\\\\(?:Trainings|TrainingData|TrainingUsage)\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure))\b)\w+'
    .'|App\\\\Modules\\\\\{[^}]*\b(?!(?:Documents|Core)\\\\|Sessions\\\\Public\b|Crm\\\\Public\b|Catalogue\\\\(?:Trainings|TrainingData|TrainingUsage)\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure))\b)(?:Sessions|Crm|Catalogue|Connectors|Finance|Admissions|Qualite)\\\\/';

it('lets Documents reach the other modules through their public services only, never their tables', function () {
    expect(moduleFilesMatching('Documents', DOCUMENTS_FOREIGN_REFERENCE))->toBe([]);

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Documents'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (namesCrmTables($code) || namesCatalogueTables($code) || namesSessionsTables($code)) {
            $offenders[] = $file->getPathname();
        }
    }
    expect($offenders)->toBe([]);
});

it('detects every way Documents could reach another module beyond its public services', function () {
    foreach ([
        'use App\Modules\Sessions\Dossiers\Dossier;',
        'use App\Modules\Crm\People\Person;',
        'use App\Modules\Catalogue\Training;',
        'use App\Modules\Finance\Invoices\Invoice;',
        'use App\Modules\Admissions\Candidatures\Candidature;',
        'use App\Modules\Connectors\Smtp\SmtpTransportFactory;',
        'use App\Modules\{Sessions\Dossiers\Dossier, Core\Access\Role};',
    ] as $code) {
        expect(preg_match(DOCUMENTS_FOREIGN_REFERENCE, $code), $code)->toBe(1);
    }
    foreach ([
        'use App\Modules\Sessions\Public\Paperwork;',
        'use App\Modules\Crm\Public\Fiches;',
        'use App\Modules\Catalogue\Trainings;',
        'use App\Modules\Core\Documents\PdfRenderer;',
        'use App\Modules\Connectors\Smtp\TenantMailer;',
        'namespace App\Modules\Documents;',
    ] as $code) {
        expect(preg_match(DOCUMENTS_FOREIGN_REFERENCE, $code), $code)->toBe(0);
    }
});

// ---------------------------------------------------------------- Questionnaires (0.11)

const QUESTIONNAIRES_TABLES = '/[\'"`](?:public\.)?(?:survey_templates|survey_campaigns|survey_invitations|survey_responses)(?:[\'"`.]|\s+as\b)'
    .'|\b(?:exists|unique):(?:public\.)?(?:survey_templates|survey_campaigns|survey_invitations|survey_responses)\b'
    .'|\b(?:from|join|into|update)\s+(?:public\.)?["`]?(?:survey_templates|survey_campaigns|survey_invitations|survey_responses)\b/i';

it('keeps every other module free of any reference to the Questionnaires module and its tables', function () {
    foreach (['Sessions', 'Crm', 'Catalogue', 'Core', 'Connectors', 'Finance', 'Admissions', 'Qualite', 'Documents'] as $module) {
        expect(moduleFilesMatching($module, '/App\\\\Modules\\\\Questionnaires\\\\|App\\\\Modules\\\\\{[^}]*\bQuestionnaires\\\\/'), $module)->toBe([])
            ->and(moduleFilesMatching($module, QUESTIONNAIRES_TABLES), $module)->toBe([]);
    }
});

/**
 * A reference to another module than Questionnaires and Core beyond their
 * public services: Sessions\Public, Crm\Public, Qualite\Public, the
 * catalogue's Trainings and the four connector classes an e-mail needs.
 */
const QUESTIONNAIRES_FOREIGN_REFERENCE = '/App\\\\Modules\\\\(?!(?:Questionnaires|Core)\b|Sessions\\\\Public\b|Crm\\\\Public\b|Qualite\\\\Public\b|Catalogue\\\\Trainings\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure))\b)\w+'
    .'|App\\\\Modules\\\\\{[^}]*\b(?!(?:Questionnaires|Core)\\\\|Sessions\\\\Public\b|Crm\\\\Public\b|Qualite\\\\Public\b|Catalogue\\\\Trainings\b|Connectors\\\\(?:Smtp\\\\(?:TenantMailer|SmtpHostRefused)|Core\\\\(?:ConnectorRun|PermanentDeliveryFailure))\b)(?:Sessions|Crm|Catalogue|Connectors|Finance|Admissions|Qualite|Documents)\\\\/';

it('lets Questionnaires reach the other modules through their public services only, never their tables', function () {
    expect(moduleFilesMatching('Questionnaires', QUESTIONNAIRES_FOREIGN_REFERENCE))->toBe([]);

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Questionnaires'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (namesCrmTables($code) || namesCatalogueTables($code) || namesSessionsTables($code)) {
            $offenders[] = $file->getPathname();
        }
    }
    expect($offenders)->toBe([]);
});

it('detects every way Questionnaires could reach another module beyond its public services', function () {
    foreach ([
        'use App\Modules\Sessions\Enrolments\Enrolment;',
        'use App\Modules\Crm\People\Person;',
        'use App\Modules\Qualite\Qualiopi\QualityProof;',
        'use App\Modules\Documents\Document;',
        'use App\Modules\{Sessions\Dossiers\Dossier, Core\Access\Role};',
    ] as $code) {
        expect(preg_match(QUESTIONNAIRES_FOREIGN_REFERENCE, $code), $code)->toBe(1);
    }
    foreach ([
        'use App\Modules\Sessions\Public\Surveys;',
        'use App\Modules\Crm\Public\Fiches;',
        'use App\Modules\Qualite\Public\Measures;',
        'use App\Modules\Catalogue\Trainings;',
        'use App\Modules\Connectors\Smtp\TenantMailer;',
    ] as $code) {
        expect(preg_match(QUESTIONNAIRES_FOREIGN_REFERENCE, $code), $code)->toBe(0);
    }
    expect(preg_match(QUESTIONNAIRES_TABLES, "DB::table('survey_invitations')"))->toBe(1);
});
