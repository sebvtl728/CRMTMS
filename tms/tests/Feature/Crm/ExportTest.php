<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Export\XlsxExporter;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * Rows of the xlsx a streamed response carries (first sheet), as arrays of strings.
 *
 * @return list<list<string>>
 */
function exRows(TestResponse $response): array
{
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

    return $rows;
}

it('exports the entreprises of the list as an xlsx download with french headers', function () {
    $user = commercial();
    $this->actingAs($user);
    $sector = crmItem(ReferenceList::Sector, 'Hôtellerie-restauration');
    Organisation::query()->create(['name' => 'Hôtel du Lac', 'city' => 'Annecy', 'siret' => '73282932000074', 'status' => 'client',
        'sector_id' => $sector->id, 'last_contact_at' => '2026-03-05 10:00:00', 'responsible_user_id' => $user->id]);

    $this->travelTo(now()->setDate(2026, 11, 12)->setTime(15, 30));
    $response = $this->get('/crm/organisations/export?columns[]=name&columns[]=siret&columns[]=city&columns[]=status&columns[]=sector&columns[]=responsible&columns[]=last_contact_at');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))->toContain('entreprises-20261112-1530.xlsx');
    expect(exRows($response))->toBe([
        ['Nom', 'SIRET', 'Ville', 'Statut', 'Secteur', 'Responsable', 'Dernier contact'],
        ['Hôtel du Lac', '73282932000074', 'Annecy', 'Client', 'Hôtellerie-restauration', $user->name, '05/03/2026'],
    ]);
});

it('uses the default columns when none is requested and ignores unknown ones', function () {
    $this->actingAs(commercial());
    Organisation::query()->create(['name' => 'Alpha']);

    $default = exRows($this->get('/crm/organisations/export'));
    expect($default[0][0])->toBe('Nom')->and($default[1][0])->toBe('Alpha');

    $this->get('/crm/organisations/export?columns[]=password')->assertSessionHasErrors('columns');
});

it('exports what the list shows: same filters, visibility and sort, never merged or deleted fiches', function () {
    $user = commercial();
    $this->actingAs($user);
    Organisation::query()->create(['name' => 'Beta', 'status' => 'client']);
    Organisation::query()->create(['name' => 'Alpha', 'status' => 'client']);
    Organisation::query()->create(['name' => 'Gamma', 'status' => 'prospect']);
    $gone = Organisation::query()->create(['name' => 'Delta', 'status' => 'client']);
    $gone->delete();
    $merged = Organisation::query()->create(['name' => 'Epsilon', 'status' => 'client']);
    $merged->merged_into_id = Organisation::query()->where('name', 'Alpha')->value('id');
    $merged->save();
    $merged->delete();

    $names = fn (string $query) => collect(exRows($this->get('/crm/organisations/export?columns[]=name&'.$query)))->skip(1)->pluck(0)->all();

    expect($names('status=client'))->toBe(['Alpha', 'Beta'])
        ->and($names('status=client&sort=name&dir=desc'))->toBe(['Beta', 'Alpha'])
        ->and($names('q=gam'))->toBe(['Gamma'])
        ->and($names('trashed=1'))->toBe(['Delta']);
    // The page shows exactly the same fiches.
    $this->get('/crm/organisations?status=client')->assertInertia(fn ($page) => $page->where('organisations.data.0.name', 'Alpha')->where('organisations.data.1.name', 'Beta')->has('organisations.data', 2));
});

it('only exports what a responsable de das can see', function () {
    $user = userWithRole(Role::ResponsableDas);
    $this->actingAs($user);
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    followDas($user, $mine);
    $visible = Organisation::query()->create(['name' => 'Visible']);
    Organisation::query()->create(['name' => 'Cachée']);
    DB::table('organisation_das')->insert(['tenant_id' => tenant()->id, 'organisation_id' => $visible->id, 'das_id' => $mine->id]);

    $rows = exRows($this->get('/crm/organisations/export?columns[]=name'));

    expect($rows)->toBe([['Nom'], ['Visible']]);
});

it('export neutralises formula injection', function () {
    $this->actingAs(commercial());
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tTab", "\rCR"] as $i => $evil) {
        Organisation::query()->create(['name' => $evil, 'city' => $evil, 'address_line1' => 'safe '.$i]);
    }
    Organisation::query()->create(['name' => 'Normal=ok', 'city' => 'A-B']);
    makePerson('=HYPERLINK("http://evil")', ['first_name' => '@x', 'email' => '+a@b.fr', 'city' => '-Paris']);

    $rows = collect(exRows($this->get('/crm/organisations/export?columns[]=name&columns[]=city')))->skip(1);
    // XML readers turn a carriage return into a line feed.
    $expected = ["'=1+1", "'+SUM(A1)", "'-2+3", "'@cmd", "'\tTab", "'\nCR"];

    expect($rows->pluck(0)->sort()->values()->all())->toBe(collect([...$expected, 'Normal=ok'])->sort()->values()->all())
        ->and($rows->pluck(1)->sort()->values()->all())->toBe(collect([...$expected, 'A-B'])->sort()->values()->all());

    $people = exRows($this->get('/crm/people/export?columns[]=last_name&columns[]=first_name&columns[]=email&columns[]=city'));
    expect($people[1])->toBe(["'=HYPERLINK(\"http://evil\")", "'@x", "'+a@b.fr", "'-Paris"]);
});

it('neutralises single cells through the exporter itself', function () {
    expect(XlsxExporter::neutralise('=A1'))->toBe("'=A1")
        ->and(XlsxExporter::neutralise('hello'))->toBe('hello')
        ->and(XlsxExporter::neutralise(''))->toBe('')
        ->and(XlsxExporter::neutralise(null))->toBeNull()
        ->and(XlsxExporter::neutralise(' =A1'))->toBe("' =A1")
        ->and(XlsxExporter::neutralise("\n=A1"))->toBe("'\n=A1")
        ->and(XlsxExporter::neutralise('a =A1'))->toBe('a =A1');
});

it('exports people with french headers, phones as shown on screen, organisations and dates', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Durand', ['civility' => 'Mme', 'first_name' => 'Léa', 'mobile' => '06 12 34 56 78', 'email' => 'lea@x.fr']);
    DB::table('people')->where('id', $person->id)->update(['created_at' => '2026-01-02 09:00:00']);
    affiliate($person, $org, ['job_title' => 'Directrice']);

    $rows = exRows($this->get('/crm/people/export?columns[]=civility&columns[]=last_name&columns[]=first_name&columns[]=organisations&columns[]=mobile&columns[]=email&columns[]=created_at'));

    expect($rows[0])->toBe(['Civilité', 'Nom', 'Prénom', 'Entreprises', 'Mobile', 'E-mail', 'Création'])
        ->and($rows[1])->toBe(['Mme', 'Durand', 'Léa', 'Hôtel du Lac', '06 12 34 56 78', 'lea@x.fr', '02/01/2026']);
});

it('exports the source system, source identifier and legal basis of people', function () {
    $this->actingAs(commercial());
    makePerson('Durand', ['source_system' => 'Formevol', 'source_id' => 'F-0042']);
    makePerson('Martin', ['is_individual' => true]);

    $rows = exRows($this->get('/crm/people/export?columns[]=last_name&columns[]=source_system&columns[]=source_id&columns[]=legal_basis&sort=last_name'));

    expect($rows)->toBe([
        ['Nom', 'Système source', 'Identifiant source', 'Base légale'],
        ['Durand', 'Formevol', 'F-0042', 'Intérêt légitime'],
        ['Martin', '', '', 'Consentement'],
    ]);
});

it('audits the export with filters and row count but no row content', function () {
    $this->actingAs(commercial());
    Organisation::query()->create(['name' => 'Alpha', 'status' => 'client', 'email' => 'secret@alpha.fr']);
    Organisation::query()->create(['name' => 'Beta', 'status' => 'client']);
    Organisation::query()->create(['name' => 'Gamma']);

    exRows($this->get('/crm/organisations/export?status=client&q=a&columns[]=name&columns[]=email'));

    $log = AuditLog::query()->where('event', 'export.run')->sole();
    expect($log->new_values)->toMatchArray(['export' => 'crm.organisations', 'rows' => 2, 'columns' => ['name', 'email']])
        ->and($log->new_values['filters'])->toMatchArray(['status' => 'client', 'q' => 'a'])
        ->and(json_encode($log->new_values))->not->toContain('secret@alpha.fr')->not->toContain('Alpha');
});

it('does not journal a people search term in clear', function () {
    $this->actingAs(commercial());
    makePerson('Dupont');

    $r = $this->get('/crm/people/export?q=Dupont&columns[]=last_name');
    exRows($r);

    $log = AuditLog::query()->where('event', 'export.run')->sole();
    expect($log->new_values['export'])->toBe('crm.people')
        ->and(json_encode($log->new_values))->not->toContain('Dupont');
});

it('refuses the export without crm.export', function () {
    $this->actingAs(assistant());
    Organisation::query()->create(['name' => 'Alpha']);

    $this->get('/crm/organisations/export')->assertForbidden();
    $this->get('/crm/people/export')->assertForbidden();
    expect(AuditLog::query()->where('event', 'export.run')->count())->toBe(0);
});

it('refuses an export above 50 000 rows without writing anything', function () {
    $this->actingAs(commercial());
    Organisation::query()->create(['name' => 'Alpha']);
    config()->set('tms.crm.export_max_rows', 0);

    $this->getJson('/crm/organisations/export')->assertUnprocessable()->assertJsonValidationErrors('export');
    $response = $this->getJson('/crm/organisations/export');
    expect($response->json('errors.export.0'))->toContain('Affinez les filtres');
    expect(AuditLog::query()->where('event', 'export.run')->count())->toBe(0);
});

it('has a limit of 50 000 rows by default', function () {
    expect(XlsxExporter::MAX_ROWS)->toBe(50000)
        ->and(config('tms.crm.export_max_rows', XlsxExporter::MAX_ROWS))->toBe(50000);
});

it('streams a large export in chunks', function () {
    $this->actingAs(commercial());
    $rows = [];
    for ($i = 0; $i < 1200; $i++) {
        $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => tenant()->id, 'name' => sprintf('Société %04d', $i), 'status' => 'prospect', 'country' => 'FR', 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('organisations')->insert($rows);

    $result = exRows($this->get('/crm/organisations/export?columns[]=name'));

    expect($result)->toHaveCount(1201)->and($result[1200][0])->toBe('Société 1199');
});

it('pre-flights an export as json without writing anything', function () {
    $this->actingAs(commercial());
    Organisation::query()->create(['name' => 'Alpha', 'status' => 'client']);
    Organisation::query()->create(['name' => 'Beta']);

    $this->getJson('/crm/organisations/export/check?status=client')->assertOk()
        ->assertExactJson(['rows' => 1, 'max' => 50000, 'allowed' => true, 'message' => null]);
    $this->getJson('/crm/people/export/check')->assertOk()->assertJsonPath('rows', 0);

    config()->set('tms.crm.export_max_rows', 1);
    $refused = $this->getJson('/crm/organisations/export/check')->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('rows', 2);
    expect($refused->json('message'))->toContain('Affinez les filtres');
    expect(AuditLog::query()->where('event', 'export.run')->count())->toBe(0);
});

it('refuses the pre-flight without crm.export', function () {
    $this->actingAs(assistant());

    $this->getJson('/crm/organisations/export/check')->assertForbidden();
    $this->getJson('/crm/people/export/check')->assertForbidden();
});

it('throttles exports with a french message', function () {
    $this->actingAs(commercial());

    foreach (range(1, 20) as $i) {
        $this->getJson('/crm/organisations/export/check')->assertOk();
    }
    $this->getJson('/crm/organisations/export/check')->assertStatus(429)->assertJsonPath('message', 'Trop d\'exports en peu de temps. Réessayez dans une minute.');
});
