<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Search\GlobalSearch;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * A Responsable DAS, and a person he sees (contact of an entreprise of his
 * DAS) who is also contact of an entreprise outside his DAS.
 *
 * @return array{user: User, person: Person, visible: Organisation, hidden: Organisation}
 */
function dasLeakScenario(): array
{
    $user = userWithRole(Role::ResponsableDas);
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    followDas($user, $mine);
    $visible = Organisation::query()->create(['name' => 'Hôtel Visible']);
    $hidden = Organisation::query()->create(['name' => 'Bureau Caché']);
    DB::table('organisation_das')->insert([
        ['tenant_id' => tenant()->id, 'organisation_id' => $visible->id, 'das_id' => $mine->id],
        ['tenant_id' => tenant()->id, 'organisation_id' => $hidden->id, 'das_id' => $other->id],
    ]);
    $person = makePerson('Zorglub', ['first_name' => 'Anne']);
    affiliate($person, $visible, ['job_title' => 'Gérante', 'work_email' => 'anne@hotel.test', 'work_phone' => '0102030405']);
    affiliate($person, $hidden, ['job_title' => 'Comptable secrète', 'work_email' => 'anne@cache.test', 'work_phone' => '0607080910']);

    return ['user' => $user, 'person' => $person, 'visible' => $visible, 'hidden' => $hidden];
}

it('exports only the affiliations of entreprises the Responsable DAS sees', function () {
    ['user' => $user] = dasLeakScenario();
    $this->actingAs($user);

    $response = $this->get('/crm/people/export?columns[]=last_name&columns[]=organisations&columns[]=job_title&columns[]=work_email&columns[]=work_phone');
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
        break;
    }
    $reader->close();
    unlink($path);

    expect($rows[1])->toBe(['Zorglub', 'Hôtel Visible', 'Gérante', 'anne@hotel.test', '01 02 03 04 05']);
});

it('keeps every affiliation in the export of an unrestricted user', function () {
    dasLeakScenario();
    $this->actingAs(commercial());

    $content = $this->get('/crm/people/export?columns[]=organisations')->streamedContent();

    expect($content)->not->toBeEmpty();
    $this->getJson('/crm/people/search?q=zorglub')->assertJsonPath('people.0.organisations', ['Hôtel Visible', 'Bureau Caché']);
});

it('shows only visible entreprises in the people list of a Responsable DAS', function () {
    ['user' => $user] = dasLeakScenario();
    $this->actingAs($user);

    $this->get('/crm/people')->assertInertia(fn ($page) => $page
        ->where('people.data.0.organisations', [['id' => Organisation::query()->where('name', 'Hôtel Visible')->value('id'), 'name' => 'Hôtel Visible', 'job_title' => 'Gérante']])
        ->where('people.data.0.cells.work_email', 'anne@hotel.test')
        ->where('people.data.0.cells.organisations', 'Hôtel Visible'));
});

it('does not reveal hidden entreprises in the global search subtitle', function () {
    ['user' => $user] = dasLeakScenario();

    $result = app(GlobalSearch::class)->query('zorglub', $user);

    expect($result['people'][0]['subtitle'])->toBe('Hôtel Visible');
});

it('does not reveal hidden entreprises in the attach dialog search', function () {
    ['user' => $user] = dasLeakScenario();
    $this->actingAs($user);

    $this->getJson('/crm/people/search?q=zorglub')->assertOk()
        ->assertJsonPath('people.0.organisations', ['Hôtel Visible']);
});
