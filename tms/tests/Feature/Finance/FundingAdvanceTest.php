<?php

use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoicePdf;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Fundings\FundingWriter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', 'Europe/Paris'));
});

/** fiSeed (September not closed: the training is not over), the OPCO asking 30 % from the 1st of September. */
function faSeed(): array
{
    $x = fiSeed(close: false);
    $x['funding'] = app(FundingWriter::class)->update($x['funding'], [...FundingWriter::input($x['funding']), 'advance_percent' => '30', 'advance_on' => '2026-09-01']);

    return $x;
}

it('bills the advance asked by a funder before the training is over', function () {
    $x = faSeed();
    $opco = "funding:{$x['funding']->id}";

    $rows = fiDue($x['dossier']->id);

    // 600 € × 30 %.
    expect(array_keys($rows))->toBe([$opco])
        ->and($rows[$opco]['total_cents'])->toBe(18000)
        ->and($rows[$opco]['advance'])->toBeTrue();
});

it('prints « Facture d\'acompte » on the invoice of an advance', function () {
    $x = faSeed();
    $rows = fiDue($x['dossier']->id);

    fiDrafts()->prepare(fiManager(), [array_values($rows)[0]['key']]);
    $invoice = Invoice::query()->sole();

    expect($invoice->is_advance)->toBeTrue()
        ->and(app(InvoicePdf::class)->html($invoice))->toContain('FACTURE D&#039;ACOMPTE');
});

it('prints « Facture d\'acompte » on an instalment before the last one', function () {
    $x = fiSeed(opco: false);
    app(BillingPlans::class)->save($x['dossier']->id, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => [
        ['due_on' => '2026-09-01', 'percent' => 30], ['due_on' => '2026-12-15', 'percent' => 70],
    ]]);

    fiDrafts()->prepare(fiManager(), [fiDue($x['dossier']->id)['contractor']['key']]);

    expect(Invoice::query()->sole()->is_advance)->toBeTrue();
});

it('reminds the advances already invoiced on the next invoice of the funder', function () {
    $x = faSeed();
    fiDrafts()->prepare(fiManager(), [array_values(fiDue($x['dossier']->id))[0]['key']]);
    fiValidate(Invoice::query()->sole());

    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
    app(Closures::class)->close($x['session']->refresh(), '2026-09');
    $opco = "funding:{$x['funding']->id}";
    $row = fiDue($x['dossier']->id)[$opco];

    // Realised share 257,14 € − the advance 180 €.
    expect($row['total_cents'])->toBe(25714 - 18000)->and($row['advance'])->toBeFalse();
    fiDrafts()->prepare(fiManager(), [$row['key']]);
    $final = Invoice::query()->where('is_advance', false)->sole();

    expect(app(InvoicePdf::class)->html($final))->toContain('Acomptes déjà facturés', 'F-2026-');
});

it('asks an advance only of a funder paying the organisme directly', function () {
    $x = fiSeed(close: false);

    try {
        app(FundingWriter::class)->update($x['funding'], [...FundingWriter::input($x['funding']), 'pays_directly' => false, 'advance_percent' => '30', 'advance_on' => '2026-09-01']);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['advance_percent'][0])->toBe('Un acompte ne se demande qu\'à un financeur qui règle directement l\'organisme : cochez « Paie directement l\'organisme ».');
    }
});

it('needs the date of the advance and a percent from 1 to 100', function () {
    $x = fiSeed(close: false);

    try {
        app(FundingWriter::class)->update($x['funding'], [...FundingWriter::input($x['funding']), 'advance_percent' => '130', 'advance_on' => '']);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['advance_percent', 'advance_on']);
    }
});
