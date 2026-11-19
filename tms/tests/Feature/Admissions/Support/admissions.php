<?php

/*
 * Helpers shared by the Admissions tests, included with require_once: Pest
 * loads only the *Test.php files.
 */

use App\Modules\Admissions\AdmissionsServiceProvider;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;

function adSetUp(): void
{
    CatalogueServiceProvider::registerReferenceUsage();
    AdmissionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
}

function adTraining(string $title = 'CAP Cuisine', ?string $rncp = 'RNCP36004', string $das = 'Hôtellerie-restauration'): Training
{
    return Training::query()->where('title', $title)->first()
        ?? Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, $das)->id, 'duration_hours' => '400.00',
            'price_company' => '6000.00', 'price_individual' => '4500.00', 'rncp_code' => $rncp]);
}

function adPerson(string $lastName = 'Durand', array $attributes = []): Person
{
    return makePerson($lastName, ['first_name' => 'Léa', 'birth_date' => '2004-05-12', ...$attributes]);
}

/**
 * @param  array<string, mixed>  $data
 */
function adCandidature(array $data = [], ?User $by = null): Candidature
{
    $by ??= admin();

    return app(CandidatureWriter::class)->create([
        'person_id' => ($data['person_id'] ?? null) ?? adPerson()->id,
        'training_id' => adTraining()->id,
        // The positioning test is compulsory before the interview: received by default.
        'positioning_received_on' => '2026-11-02',
        ...$data,
    ], $by);
}

/**
 * A user of a custom role holding exactly $grants (permission => scope; null: « all »).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function adUser(array $grants): User
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
