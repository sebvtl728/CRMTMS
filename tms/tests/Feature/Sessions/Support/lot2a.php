<?php

/*
 * Helpers shared by the tests of task 8 (history, RGPD, home), included with
 * require_once: Pest loads only the *Test.php files.
 */

use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\DB;

function l2Training(string $title = 'HACCP 3 jours', string $das = 'Hôtellerie-restauration'): Training
{
    return Training::query()->where('title', $title)->first()
        ?? Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, $das)->id, 'duration_hours' => '21.00']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function l2Session(?Training $training = null, array $overrides = []): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => ($training ?? l2Training())->id, 'kind' => 'inter', 'organisation_id' => null,
        'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
        ...$overrides,
    ]);
}

/**
 * A dossier of 1 000 € HT (one training line priced per trainee), the
 * contractant an entreprise or a particulier.
 *
 * @param  array<string, mixed>  $overrides
 */
function l2Dossier(Organisation|Person|null $account = null, ?Training $training = null, array $overrides = []): Dossier
{
    $account ??= Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $training ??= l2Training();

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => $account instanceof Organisation ? $account->id : null,
        'account_person_id' => $account instanceof Person ? $account->id : null,
        'das_id' => $training->das_id,
        'lines' => [[
            'kind' => 'training', 'training_id' => $training->id, 'label' => $training->title, 'quantity' => '1',
            'unit' => 'forfait', 'unit_price' => '1000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee',
        ]],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function l2Enrol(Dossier $dossier, TrainingSession $session, Person $person, array $extra = []): Enrolment
{
    return app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, $person->id, $extra);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function l2Funding(Dossier $dossier, array $overrides = []): Funding
{
    return app(FundingWriter::class)->create($dossier, [
        'funder_organisation_id' => (Organisation::query()->where('name', 'OPCO EP')->first() ?? Organisation::query()->create(['name' => 'OPCO EP']))->id,
        'funder_person_id' => null,
        'funding_type_id' => crmItem(ReferenceList::FundingType, 'OPCO')->id,
        'dossier_line_id' => null,
        'enrolment_id' => null,
        'amount' => '600,00',
        'status' => 'requested',
        'agreement_number' => null,
        'agreed_on' => null,
        'notes' => null,
        ...$overrides,
    ]);
}

/**
 * A user of a custom role holding exactly $grants (permission => scope; null: « all »).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function l2User(array $grants): User
{
    $user = userWithPermissions(array_keys($grants));
    $role = $user->roles()->firstOrFail();
    foreach ($grants as $permission => $scope) {
        if ($scope !== null) {
            DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => $scope->value]);
        }
    }
    Scopes::forget();

    return $user;
}
