<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @return list<list<string>>
 */
function oxRows(TestResponse $response): array
{
    $response->assertOk();
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

it('exports the opportunities with french headers and values', function () {
    $user = commercial();
    $this->actingAs($user);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 15:30', 'Europe/Paris'));
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    makeOpportunity($org, ['title' => 'HACCP', 'amount' => '1234.5', 'expected_close_on' => '2026-12-20', 'responsible_user_id' => $user->id,
        'priority_id' => crmItem(ReferenceList::Priority, 'Élevé')->id]);

    $response = $this->get('/crm/opportunities/export');
    expect($response->headers->get('content-disposition'))->toContain('opportunites-20261114-1530.xlsx');
    expect(oxRows($response))->toBe([
        ['Objet', 'Compte', 'Type de compte', 'DAS', 'Étape', 'Probabilité (%)', 'Montant (€)', 'Montant pondéré (€)', 'Clôture prévue', 'Priorité', 'Responsable'],
        ['HACCP', 'Le Bistrot', 'Entreprise', 'Hôtellerie-restauration', 'Nouveau', '10', '1 234,50', '123,45', '20/12/2026', 'Élevé', $user->name],
    ]);

    $this->get('/crm/opportunities/export?columns[]=password')->assertSessionHasErrors('columns');
});

it('export filtered identical to the list', function () {
    $user = commercial();
    $this->actingAs($user);
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    makeOpportunity($org, ['title' => 'Alpha', 'amount' => 300, 'expected_close_on' => '2026-12-01', 'responsible_user_id' => $user->id]);
    makeOpportunity($org, ['title' => 'Bravo', 'amount' => 100, 'expected_close_on' => '2026-11-20', 'das_id' => $other->id]);
    $charlie = makeOpportunity($org, ['title' => 'Charlie', 'amount' => 200]);
    app(OpportunityStage::class)->move($charlie, pipelineStage('Étude'), null, null, null);
    $gone = Organisation::query()->create(['name' => 'Fermé']);
    makeOpportunity($gone, ['title' => 'Fantôme']);
    $gone->delete();
    $deleted = makeOpportunity($org, ['title' => 'Supprimée']);
    $deleted->delete();

    $list = fn (string $query) => collect($this->get('/crm/opportunities?'.$query)->original->getData()['page']['props']['opportunities']['data'])->pluck('title')->all();
    $export = fn (string $query) => collect(oxRows($this->get('/crm/opportunities/export?columns[]=title&'.$query)))->skip(1)->pluck(0)->values()->all();

    foreach (['', 'das='.$mine->id, 'das='.$other->id, 'q=char', 'mine=1', 'stage='.pipelineStage('Étude')->id,
        'sort=amount&dir=desc', 'sort=weighted_amount', 'close_from=2026-11-25', 'target=individual'] as $query) {
        expect($export($query))->toBe($list($query));
    }
    expect($export('sort=amount&dir=desc'))->toBe(['Alpha', 'Charlie', 'Bravo']);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $other);
    $this->actingAs($responsable);
    expect($export(''))->toBe(['Bravo']);
});

it('neutralises formulas, audits without content and masks the search term', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => '=HYPERLINK("http://x")']);
    makeOpportunity($org, ['title' => '+cmd|calc']);

    $rows = oxRows($this->get('/crm/opportunities/export?q=cmd&columns[]=title&columns[]=account'));
    expect($rows[1])->toBe(["'+cmd|calc", "'=HYPERLINK(\"http://x\")"]);

    $log = AuditLog::query()->where('event', 'export.run')->sole();
    expect($log->new_values)->toMatchArray(['export' => 'crm.opportunities', 'rows' => 1, 'columns' => ['title', 'account']])
        ->and(json_encode($log->new_values))->not->toContain('cmd');
});

it('needs crm.export and pre-flights like the other lists', function () {
    $this->actingAs(assistant());
    makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $this->get('/crm/opportunities/export')->assertForbidden();
    $this->getJson('/crm/opportunities/export/check')->assertForbidden();

    $this->actingAs(commercial());
    $this->getJson('/crm/opportunities/export/check')->assertOk()->assertJson(['rows' => 1, 'allowed' => true]);
    config()->set('tms.crm.export_max_rows', 0);
    $this->getJson('/crm/opportunities/export/check')->assertOk()->assertJson(['allowed' => false]);
    expect(AuditLog::query()->where('event', 'export.run')->count())->toBe(0);
});
