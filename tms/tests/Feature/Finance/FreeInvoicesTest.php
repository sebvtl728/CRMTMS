<?php

use App\Modules\Finance\Integrity;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/free.php';
require_once __DIR__.'/Support/invoices.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    frGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
});

it('stores a free invoice without dossier, and keeps the origin and the dossier consistent', function () {
    $region = frRegion();
    $invoice = frDraft($region, [['Composante S-2026-0001', 120000, null]]);

    expect($invoice->isFree())->toBeTrue()->and($invoice->dossier_id)->toBeNull();

    $dossier = l2Dossier();
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['dossier_id' => $dossier->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['origin' => 'dossier']))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['origin' => 'other']))->toThrow(QueryException::class);
});

it('keeps the hash of a dossier invoice and hashes the free references', function () {
    $x = fiSeed();
    $draft = fiDrafts()->manual(fiManager(), $x['dossier']->id, Invoice::CONTRACTOR);
    $canonical = Integrity::canonical($draft);
    expect($canonical)->not->toContain('origin')->not->toContain('buyer_reference')
        ->not->toContain('service_code')->not->toContain('cotraitant_id');

    $partner = frCotraitant();
    $free = frDraft(frRegion(), [['Composante CAP', 100000, null], ['Composante co-traitant', 50000, $partner->id]], ['buyer_reference' => 'EJ-123', 'service_code' => 'SERV-9']);
    expect(Integrity::canonical($free))->toContain('"origin":"free"')->toContain('"buyer_reference":"EJ-123"')
        ->toContain('"service_code":"SERV-9"')->toContain('"cotraitant_id":"'.$partner->id.'"');
});

it('freezes the references of a validated free invoice', function () {
    $invoice = frDraft(frRegion(), [['Composante', 1000, null]], ['buyer_reference' => 'EJ-1']);
    $invoice->forceFill(['status' => InvoiceStatus::Validated, 'number' => 'F-2026-09999', 'issued_on' => '2026-11-20'])->save();

    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['buyer_reference' => 'EJ-2']))->toThrow(QueryException::class);
});

it('shows a free invoice to a scope « Tout » only', function () {
    $invoice = frDraft(frRegion(), [['Composante', 120000, null]]);
    $all = frAccountant();
    $das = frAccountant(App\Modules\Core\Access\AccessScope::Das);

    $this->actingAs($all)->get('/finance/invoices')->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoices.data.0.id', $invoice->id)->where('invoices.data.0.origin', 'free')->where('invoices.data.0.dossier', null));
    $this->actingAs($all)->get('/finance/invoices/'.$invoice->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoice.origin', 'free')->where('invoice.dossier', null));

    $this->actingAs($das)->get('/finance/invoices')->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoices.data', []));
    $this->actingAs($das)->get('/finance/invoices/'.$invoice->id)->assertNotFound();
    $this->actingAs($das)->get('/finance/invoices/'.$invoice->id.'/pdf')->assertNotFound();
});

it('creates a free draft for a client, refused without the scope « Tout »', function () {
    $region = frRegion();
    $company = frCompany();
    $all = frAccountant();

    $response = $this->actingAs($all)->post('/finance/invoices/free', [
        'company_id' => $company->id, 'recipient_type' => 'organisation', 'recipient_id' => $region->id, 'buyer_reference' => ' EJ-2026-55 ',
    ]);
    $invoice = Invoice::query()->where('origin', 'free')->sole();
    $response->assertRedirect('/finance/invoices/'.$invoice->id);
    expect($invoice->recipient_id)->toBe($region->id)->and($invoice->buyer_reference)->toBe('EJ-2026-55')
        ->and($invoice->dossier_id)->toBeNull()->and($invoice->isDraft())->toBeTrue();

    $this->actingAs($all)->post('/finance/invoices/free', ['company_id' => $company->id, 'recipient_type' => 'organisation', 'recipient_id' => (string) Illuminate\Support\Str::uuid7()])
        ->assertSessionHasErrors(['recipient_id' => App\Modules\Finance\Invoices\FreeInvoices::NO_RECIPIENT]);
    $this->actingAs($all)->post('/finance/invoices/free', ['company_id' => (string) Illuminate\Support\Str::uuid7(), 'recipient_type' => 'organisation', 'recipient_id' => $region->id])
        ->assertSessionHasErrors('company_id');
    $this->actingAs(frAccountant(App\Modules\Core\Access\AccessScope::Das))
        ->post('/finance/invoices/free', ['company_id' => $company->id, 'recipient_type' => 'organisation', 'recipient_id' => $region->id])
        ->assertForbidden();
});

it('saves the lines of a free draft with their co-contractor', function () {
    $invoice = frDraft(frRegion(), []);
    $partner = frCotraitant();
    $all = frAccountant();

    $this->actingAs($all)->put('/finance/invoices/'.$invoice->id, [
        'service_code' => 'FORM-44',
        'lines' => [
            ['label' => 'Composante S-2026-0001 (CAP)', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '1 200,00'],
            ['label' => 'Composante S-2026-0002', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '800,00', 'cotraitant_id' => $partner->id],
        ],
    ])->assertSessionHasNoErrors();
    $invoice->refresh();
    expect($invoice->total_ht_cents)->toBe(200000)->and($invoice->service_code)->toBe('FORM-44')
        ->and($invoice->lines()->pluck('cotraitant_id')->all())->toBe([null, $partner->id]);

    $this->actingAs($all)->put('/finance/invoices/'.$invoice->id, ['lines' => [
        ['label' => 'Composante', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '10', 'cotraitant_id' => (string) Illuminate\Support\Str::uuid7()],
    ]])->assertSessionHasErrors(['lines.0.cotraitant_id' => App\Modules\Finance\Invoices\InvoiceDrafts::NO_COTRAITANT]);

    $this->actingAs($all)->post('/finance/invoices/'.$invoice->id.'/recompute')->assertStatus(409);
});

it('refuses a co-contractor on a dossier invoice', function () {
    $x = fiSeed();
    $draft = fiDrafts()->manual(fiManager(), $x['dossier']->id, Invoice::CONTRACTOR);
    $lines = $draft->lines()->get()->map(fn ($l) => ['id' => $l->id, 'label' => $l->label, 'quantity' => (string) $l->quantity, 'unit' => $l->unit, 'unit_price' => (string) ($l->unit_price_cents / 100)])->all();
    $lines[] = ['label' => 'Ligne', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '10', 'cotraitant_id' => frCotraitant()->id];

    expect(fn () => fiDrafts()->update($draft, ['lines' => $lines]))->toThrow(Illuminate\Validation\ValidationException::class);
});
