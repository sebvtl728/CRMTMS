<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\QuoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-14 15:30', 'Europe/Paris'));
});

/**
 * @return list<list<mixed>> cells of the first sheet (OpenSpout cells)
 */
function qxCells(TestResponse $response): array
{
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->getCells();
        }
        break;
    }
    $reader->close();
    unlink($path);

    return $rows;
}

/**
 * @return list<list<string>>
 */
function qxRows(TestResponse $response): array
{
    return array_map(fn (array $cells) => array_map(function ($cell) {
        $v = $cell->getValue();

        return $v instanceof DateTimeInterface ? $v->format('d/m/Y') : (string) $v;
    }, $cells), qxCells($response));
}

it('exports the quotes with french headers and typed cells', function () {
    $this->actingAs(commercial());
    $quote = makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']), ['title' => 'HACCP']), null, [[
        'training_id' => null, 'label' => 'HACCP', 'description' => null, 'quantity' => '1.00',
        'unit' => 'stagiaire', 'unit_price' => '1234.50', 'discount_percent' => '0.00', 'vat_rate' => '20.00',
    ]]);
    $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => CarbonImmutable::parse('2026-11-14 00:30', 'Europe/Paris')])->save();

    $response = $this->get('/crm/quotes/export');
    expect($response->headers->get('content-disposition'))->toContain('devis-20261114-1530.xlsx');
    expect(qxRows($response))->toBe([
        ['Numéro', 'Date', 'Statut', 'Compte', 'Opportunité', 'Total HT (€)', 'Total TTC (€)'],
        [$quote->number, '14/11/2026', 'Envoyé', 'Le Bistrot', 'HACCP', '1234.5', '1481.4'],
    ]);

    $cells = qxCells($this->get('/crm/quotes/export?columns[]=number&columns[]=total_ht&columns[]=total_vat&columns[]=issued_on&columns[]=valid_until&columns[]=sent_at'))[1];
    expect($cells[0])->toBeInstanceOf(StringCell::class)
        // Formats « 1 234,50 € » and « dd/mm/yyyy »: ExportCell (see OpportunityExportTest).
        ->and($cells[1])->toBeInstanceOf(NumericCell::class)->and($cells[1]->getValue())->toBe(1234.5)
        ->and($cells[2])->toBeInstanceOf(NumericCell::class)->and($cells[2]->getValue())->toBe(246.9)
        ->and($cells[3])->toBeInstanceOf(DateTimeCell::class)->and($cells[3]->getValue()->format('Y-m-d'))->toBe('2026-11-14')
        ->and($cells[4]->getValue()->format('Y-m-d'))->toBe('2026-12-14')
        // Sent at 00:30 Paris on the 14th (still the 13th in UTC).
        ->and($cells[5]->getValue()->format('Y-m-d'))->toBe('2026-11-14');

    $this->get('/crm/quotes/export?columns[]=recipient_snapshot')->assertSessionHasErrors('columns');
});

it('export filtered identical to the list', function () {
    $user = commercial();
    $this->actingAs($user);
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $alpha = makeQuote(makeOpportunity($org, ['title' => 'Alpha', 'responsible_user_id' => $user->id]));
    $bravo = makeQuote(makeOpportunity($org, ['title' => 'Bravo', 'das_id' => $other->id]));
    $bravo->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()])->save();
    makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Charlie SARL']), ['title' => 'Charlie']));
    $gone = Organisation::query()->create(['name' => 'Fermé']);
    makeQuote(makeOpportunity($gone, ['title' => 'Fantôme']));
    $gone->delete();
    $deleted = makeQuote(makeOpportunity($org, ['title' => 'Supprimé']));
    $deleted->delete();

    $list = fn (string $query) => collect($this->get('/crm/quotes?'.$query)->original->getData()['page']['props']['quotes']['data'])->pluck('number')->all();
    $export = fn (string $query) => collect(qxRows($this->get('/crm/quotes/export?columns[]=number&'.$query)))->skip(1)->pluck(0)->values()->all();

    foreach (['', 'das='.$other->id, 'q=charlie', 'q=alpha', 'status=sent', 'responsible='.$user->id,
        'sort=number&dir=desc', 'sort=total', 'issued_from=2026-11-15'] as $query) {
        expect($export($query))->toBe($list($query));
    }
    expect($export(''))->toHaveCount(3)->and($export('status=sent'))->toBe([$bravo->number])
        ->and($export('sort=number&dir=asc')[0])->toBe($alpha->number);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $other);
    $this->actingAs($responsable);
    expect($export(''))->toBe([$bravo->number]);
});

it('needs crm.export, pre-flights and audits without content', function () {
    makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot'])));
    $this->actingAs(assistant());
    $this->get('/crm/quotes/export')->assertForbidden();
    $this->getJson('/crm/quotes/export/check')->assertForbidden();

    $this->actingAs(commercial());
    $this->getJson('/crm/quotes/export/check')->assertOk()->assertJson(['rows' => 1, 'allowed' => true]);
    expect(AuditLog::query()->where('event', 'export.run')->count())->toBe(0);

    qxRows($this->get('/crm/quotes/export?q=bistrot'));
    $log = AuditLog::query()->where('event', 'export.run')->sole();
    expect($log->new_values)->toMatchArray(['export' => 'crm.quotes', 'rows' => 1])
        ->and(json_encode($log->new_values))->not->toContain('bistrot');

    $this->get('/crm/quotes')->assertInertia(fn ($page) => $page->where('can.export', true));
});
