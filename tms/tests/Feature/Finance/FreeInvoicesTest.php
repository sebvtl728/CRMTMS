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
