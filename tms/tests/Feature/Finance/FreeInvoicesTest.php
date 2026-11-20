<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\Duplicates\Merger;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Invoices\CreditNotes;
use App\Modules\Finance\Invoices\FreeInvoices;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceDrafts;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $das = frAccountant(AccessScope::Das);

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

    $this->actingAs($all)->post('/finance/invoices/free', ['company_id' => $company->id, 'recipient_type' => 'organisation', 'recipient_id' => (string) Str::uuid7()])
        ->assertSessionHasErrors(['recipient_id' => FreeInvoices::NO_RECIPIENT]);
    $this->actingAs($all)->post('/finance/invoices/free', ['company_id' => (string) Str::uuid7(), 'recipient_type' => 'organisation', 'recipient_id' => $region->id])
        ->assertSessionHasErrors('company_id');
    $this->actingAs(frAccountant(AccessScope::Das))
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
        ['label' => 'Composante', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '10', 'cotraitant_id' => (string) Str::uuid7()],
    ]])->assertSessionHasErrors(['lines.0.cotraitant_id' => InvoiceDrafts::NO_COTRAITANT]);

    $this->actingAs($all)->post('/finance/invoices/'.$invoice->id.'/recompute')->assertStatus(409);
});

it('refuses a co-contractor on a dossier invoice', function () {
    $x = fiSeed();
    $draft = fiDrafts()->manual(fiManager(), $x['dossier']->id, Invoice::CONTRACTOR);
    $lines = $draft->lines()->get()->map(fn ($l) => ['id' => $l->id, 'label' => $l->label, 'quantity' => (string) $l->quantity, 'unit' => $l->unit, 'unit_price' => (string) ($l->unit_price_cents / 100)])->all();
    $lines[] = ['label' => 'Ligne', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '10', 'cotraitant_id' => frCotraitant()->id];

    expect(fn () => fiDrafts()->update($draft, ['lines' => $lines]))->toThrow(ValidationException::class);
});

it('validates a free invoice: number, chained journal, PDF with the buyer references and the co-contractor', function () {
    $partner = frCotraitant('Formation Atlantique');
    $draft = frDraft(frRegion(), [['Composante S-2026-0001', 120000, null], ['Composante S-2026-0002', 80000, $partner->id]], ['buyer_reference' => 'EJ-123', 'service_code' => 'SERV-9']);
    $user = frAccountant();

    $invoice = app(InvoiceValidator::class)->validate($draft, $user);

    expect($invoice->number)->toEndWith('-2026-00001')
        ->and($invoice->status)->toBe(InvoiceStatus::Validated)->and($invoice->hash)->not->toBeNull()
        ->and($invoice->recipient_snapshot['cotraitants'] ?? null)->toBe([$partner->id => 'Formation Atlantique']);
    $text = frSentText();
    expect($text)->toContain("N° d'engagement : EJ-123")->toContain('Code service : SERV-9')
        ->toContain('mandataire du groupement')->toContain('Réalisé par Formation Atlantique (co-traitant)');
    expect(Integrity::verify())->toBe(['count' => 1, 'broken' => null]);
});

it('refuses to validate an empty free invoice', function () {
    $draft = frDraft(frRegion(), []);

    expect(fn () => app(InvoiceValidator::class)->validate($draft, frAccountant()))
        ->toThrow(UserFacingHttpException::class);
});

it('credits a free invoice, the co-contractor lines given back, never beyond the invoice', function () {
    $partner = frCotraitant();
    $user = frAccountant();
    $validator = app(InvoiceValidator::class);
    $invoice = $validator->validate(frDraft(frRegion(), [['CAP', 100000, null], ['Co-traitant', 50000, $partner->id]], ['buyer_reference' => 'EJ-1']), $user);

    $coLine = $invoice->lines()->where('cotraitant_id', $partner->id)->value('id');
    $credit = app(CreditNotes::class)->create($user, $invoice, [$coLine]);
    expect($credit->isFree())->toBeTrue()->and($credit->dossier_id)->toBeNull()->and($credit->buyer_reference)->toBe('EJ-1')
        ->and($credit->lines()->sole()->cotraitant_id)->toBe($partner->id)->and($credit->total_ht_cents)->toBe(-50000);
    expect(fn () => app(CreditNotes::class)->create($user, $invoice, [$coLine]))
        ->toThrow(UserFacingHttpException::class);
    $validator->validate($credit, $user);

    // Another credit note of the whole invoice would exceed what is left (1 500 − 500).
    $again = app(CreditNotes::class)->create($user, $invoice);
    expect(fn () => $validator->validate($again, $user))->toThrow(UserFacingHttpException::class);
});

it('lists the clients and co-contractors of a free invoice, and offers the creation on « Tout »', function () {
    frRegion();
    $person = makePerson('Régnier', ['first_name' => 'Anne']);
    $all = frAccountant();

    $this->actingAs($all)->getJson('/finance/clients?q=Rég')->assertOk()
        ->assertJsonFragment(['type' => 'organisation', 'label' => 'Région Pays de la Loire']);
    $only = $this->actingAs($all)->getJson('/finance/clients?q=Rég&only=organisation')->json('clients');
    expect(collect($only)->pluck('type')->unique()->all())->toBe(['organisation']);
    $this->actingAs($all)->getJson('/finance/clients?q=R')->assertOk()->assertExactJson(['clients' => []]);
    $this->actingAs(userWithPermissions([Permissions::VIEW]))->getJson('/finance/clients?q=Rég')->assertForbidden();

    $this->actingAs($all)->get('/finance/invoices')->assertInertia(fn ($page) => $page->where('can.create_free', true));
    $this->actingAs(frAccountant(AccessScope::Das))->get('/finance/invoices')
        ->assertInertia(fn ($page) => $page->where('can.create_free', false));
});

it('shows the co-contractor of a line on the page of a free invoice', function () {
    $partner = frCotraitant('Formation Atlantique');
    $invoice = frDraft(frRegion(), [['Composante', 1000, $partner->id]], ['buyer_reference' => 'EJ-7']);

    $this->actingAs(frAccountant())->get('/finance/invoices/'.$invoice->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoice.buyer_reference', 'EJ-7')->where('invoice.rhythm_label', null)
            ->where('invoice.lines.0.cotraitant_id', $partner->id)->where('invoice.lines.0.cotraitant_label', 'Formation Atlantique'));
});

it('refuses the client search to a scope narrower than « Tout »', function () {
    frRegion();
    $this->actingAs(frAccountant(AccessScope::Das))->getJson('/finance/clients?q=Rég')->assertForbidden();
});

it('keeps the co-contractor of a credit-note line even once its fiche is in the trash', function () {
    $partner = frCotraitant();
    $user = frAccountant();
    $invoice = app(InvoiceValidator::class)->validate(frDraft(frRegion(), [['Co-traitant', 50000, $partner->id]]), $user);
    $partner->delete();
    $credit = app(CreditNotes::class)->create($user, $invoice);
    $line = $credit->lines()->sole();

    // Saved with its co-contractor as it was, then with another one: the credit note keeps the corrected line's.
    $other = frCotraitant('Autre');
    fiDrafts()->update($credit, ['notes' => 'Erreur de prix', 'lines' => [[
        'id' => $line->id, 'label' => $line->label, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '-500,00', 'cotraitant_id' => $other->id,
    ]]]);
    expect($credit->refresh()->notes)->toBe('Erreur de prix')->and($line->refresh()->cotraitant_id)->toBe($partner->id);
});

it('links the client and the co-contractors of free invoices to their fiches (import undo, merge)', function () {
    $region = frRegion();
    $partner = frCotraitant();
    $dropped = frCotraitant('Formation Atlantique (doublon)');
    $draft = frDraft($region, [['Co-traitant', 1000, $dropped->id]]);

    expect(LinkedData::any($region, []))->toBeTrue()
        ->and(LinkedData::any($dropped, []))->toBeTrue()
        ->and(LinkedData::any(frCotraitant('Sans lien'), []))->toBeFalse();

    app(Merger::class)->organisations($partner, $dropped, []);
    expect($draft->lines()->sole()->cotraitant_id)->toBe($partner->id);
});

it('names a person client in the payouts and refuses a company in the trash at validation', function () {
    $person = makePerson('Durand', ['first_name' => 'Léa']);
    $user = frAccountant();
    $draft = frDraft(frRegion(), [['Composante', 1000, null]]);
    $draft->forceFill(['recipient_type' => 'person', 'recipient_id' => $person->id])->save();
    Company::query()->whereKey($draft->company_id)->first()->delete();

    expect(fn () => app(InvoiceValidator::class)->validate($draft, $user))
        ->toThrow(UserFacingHttpException::class, InvoiceValidator::COMPANY_GONE);
});
