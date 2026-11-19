<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Invoices\CreditNotes;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceJournalEntry;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Invoices\InvoiceNumbering;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Public\BillingGuards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    fvGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * Gotenberg answers « %PDF-1.7 facture N » (N = call); the HTML is kept by Http.
 */
function fvGotenberg(): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $calls = 0;
    Http::fake(['gotenberg:3000/*' => function () use (&$calls) {
        $calls++;

        return Http::response("%PDF-1.7 facture {$calls}", 200, ['Content-Type' => 'application/pdf']);
    }]);
}

/** The text of the last HTML sent to Gotenberg (tags and entities removed, spaces squeezed). */
function fvSentText(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    $html = (string) preg_replace(['#<style\b.*?</style>#s', '#<[^>]+>#'], ' ', $html);

    return fvText(html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
}

function fvText(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{202F}"], ' ', $text)));
}

/**
 * The company of the dossiers (exempt from VAT): identity, NDA, VAT
 * number, payment in 30 days; the contractor with an address and a SIRET.
 */
function fvIdentities(): void
{
    Company::query()->where('is_default', true)->update([
        'name' => 'CAP FORMATION', 'siret' => '73282932000074', 'nda_number' => '84740123474', 'vat_number' => 'FR40732829320',
        'invoice_footer' => 'IBAN FR76 1234 5678 9012',
    ]);
    Organisation::query()->where('name', 'Hôtel du Parc')->update([
        'siret' => '41234567800018', 'address_line1' => '1 quai du Lac', 'postal_code' => '74000', 'city' => 'Annecy', 'email' => 'compta@hotel-du-parc.test',
    ]);
}

/** Who prepares and validates the invoices of every dossier. */
function fvAccountant(): User
{
    return fiManager([Permissions::VALIDATE => null]);
}

/** The invoice draft of the recipient (« contractor » or « funding:<id> »), prepared from what is due. */
function fvDraft(array $x, string $recipient, User $user): Invoice
{
    fiDrafts()->prepare($user, [$x['dossier']->id.'|'.$recipient]);

    return Invoice::query()->where('dossier_id', $x['dossier']->id)->where('status', InvoiceStatus::Draft)->where('kind', Invoice::INVOICE)
        ->where('recipient_key', $recipient === 'contractor' ? 'contractor' : substr($recipient, 8))->sole();
}

function fvValidate(Invoice $draft, ?User $user = null): Invoice
{
    return app(InvoiceValidator::class)->validate($draft, $user ?? fvAccountant());
}

/** The SQL error of $fn run in a savepoint, null when it went through. */
function fvRefused(Closure $fn): ?string
{
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return null;
}

// ---------------------------------------------------------------- validation

it('validates a draft: number, dates, frozen recipient, chained hash, journal, archived PDF, plan locked', function () {
    $x = fiSeed();
    fvIdentities();
    $user = fvAccountant();
    $draft = fvDraft($x, 'contractor', $user);

    $invoice = fvValidate($draft, $user)->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Validated)
        ->and($invoice->number)->toBe('F-2026-00001')
        ->and($invoice->issued_on->toDateString())->toBe('2026-11-17')
        ->and($invoice->due_on->toDateString())->toBe('2026-12-17')
        ->and($invoice->validated_by)->toBe($user->id)
        ->and($invoice->total_ht_cents)->toBe(17143)
        ->and($invoice->total_ttc_cents)->toBe(17143)
        ->and($invoice->recipient_snapshot)->toMatchArray([
            'name' => 'Hôtel du Parc', 'address_line1' => '1 quai du Lac', 'postal_code' => '74000', 'city' => 'Annecy',
            'siret' => '41234567800018', 'siren' => '412345678', 'email' => 'compta@hotel-du-parc.test',
            'dossier_number' => $x['dossier']->number, 'agreement_number' => null,
        ])
        ->and($invoice->previous_hash)->toBeNull()
        ->and($invoice->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($invoice->hash)->toBe(hash('sha256', Integrity::canonical($invoice)));

    $entry = InvoiceJournalEntry::query()->sole();
    expect($entry->only(['invoice_id', 'position', 'number', 'kind', 'total_ht_cents', 'total_ttc_cents', 'hash', 'previous_hash']))->toBe([
        'invoice_id' => $invoice->id, 'position' => 1, 'number' => 'F-2026-00001', 'kind' => 'invoice',
        'total_ht_cents' => 17143, 'total_ttc_cents' => 17143, 'hash' => $invoice->hash, 'previous_hash' => null,
    ]);

    expect($invoice->pdf_path)->toBe(TenantStorage::path('invoices', $invoice->id.'/F-2026-00001.pdf'))
        ->and(Storage::disk(TenantStorage::DISK)->get($invoice->pdf_path))->toBe('%PDF-1.7 facture 1')
        ->and($invoice->pdf_sha256)->toBe(hash('sha256', '%PDF-1.7 facture 1'))
        ->and($entry->pdf_sha256)->toBe($invoice->pdf_sha256)
        ->and(app(BillingPlans::class)->isLocked($x['dossier']->id))->toBeTrue()
        // Who pays directly and the trash are frozen too (Sessions guards).
        ->and(BillingGuards::fundingLocked($x['dossier']->id))->toBe(BillingPlans::HAS_INVOICES)
        ->and(BillingGuards::dossierTrashable($x['dossier']->id))->toBe(BillingPlans::HAS_INVOICES);

    // The second piece of the organisme chains to the first.
    $opco = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user)->refresh();
    expect($opco->number)->toBe('F-2026-00002')
        ->and($opco->previous_hash)->toBe($invoice->hash)
        ->and($opco->hash)->toBe(hash('sha256', Integrity::canonical($opco).$invoice->hash))
        ->and($opco->recipient_snapshot)->toMatchArray(['name' => 'OPCO EP', 'agreement_number' => 'PEC-42'])
        ->and(InvoiceJournalEntry::query()->orderBy('position')->pluck('position')->all())->toBe([1, 2]);

    expect(fn () => fvValidate($invoice))->toThrow(UserFacingHttpException::class, InvoiceValidator::NOT_DRAFT);
});

it('numbers per company, kind and Paris year, five digits', function () {
    $a = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();
    $b = Company::factory()->create(['invoice_prefix' => 'FB', 'credit_note_prefix' => 'AVB']);
    $numbering = app(InvoiceNumbering::class);
    $at = CarbonImmutable::parse('2026-06-15 10:00', 'Europe/Paris');

    DB::transaction(function () use ($numbering, $a, $b, $at) {
        expect($numbering->next($a, Invoice::INVOICE, $at))->toBe('F-2026-00001')
            ->and($numbering->next($a, Invoice::INVOICE, $at))->toBe('F-2026-00002')
            ->and($numbering->next($a, Invoice::CREDIT_NOTE, $at))->toBe('AV-2026-00001')
            ->and($numbering->next($b, Invoice::INVOICE, $at))->toBe('FB-2026-00001')
            ->and($numbering->next($b, Invoice::CREDIT_NOTE, $at))->toBe('AVB-2026-00001')
            // 31/12 23:00 UTC is 1st January in Paris: a new year starts at 00001.
            ->and($numbering->next($a, Invoice::INVOICE, CarbonImmutable::parse('2026-12-31 22:59', 'UTC')))->toBe('F-2026-00003')
            ->and($numbering->next($a, Invoice::INVOICE, CarbonImmutable::parse('2026-12-31 23:00', 'UTC')))->toBe('F-2027-00001');
    });
});

it('restarts the numbers at 00001 on the first validation of a new year', function () {
    $x = fiSeed();
    $user = fvAccountant();
    expect(fvValidate(fvDraft($x, 'contractor', $user), $user)->number)->toBe('F-2026-00001');

    $this->travelTo(CarbonImmutable::parse('2027-01-04 09:00', 'Europe/Paris'));
    $opco = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    expect($opco->number)->toBe('F-2027-00001')
        ->and($opco->issued_on->toDateString())->toBe('2027-01-04')
        ->and($opco->due_on->toDateString())->toBe('2027-02-03');
});

it('prints the exemption notice of the validation day: the CIBS from 2027, a credit note too', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fvDraft($x, 'contractor', $user);
    // Prepared in 2026, validated in 2027: the notice of the validation day.
    expect($draft->refresh()->vat_mention)->toBe('Exonération de TVA, art. 261, 4-4° a du CGI');
    $this->travelTo(CarbonImmutable::parse('2027-01-04 09:00', 'Europe/Paris'));
    $invoice = fvValidate($draft, $user);
    expect($invoice->vat_mention)->toBe('Exonération de TVA, art. L. 213-93 du CIBS')
        ->and(fvSentText())->toContain('Exonération de TVA, art. L. 213-93 du CIBS');

    $this->travelTo(CarbonImmutable::parse('2027-01-05 09:00', 'Europe/Paris'));
    $credit = fvValidate(fvCreditPrices(app(CreditNotes::class)->create($user, $invoice), ['-171,43']), $user);
    expect($credit->vat_mention)->toBe('Exonération de TVA, art. L. 213-93 du CIBS')->and($credit->total_vat_cents)->toBe(0);
});

/**
 * A runtime connection (tms_app) of its own, outside the per-test
 * transaction, acting for $tenantId.
 */
function fvConnection(string $name, string $tenantId): Connection
{
    config(["database.connections.{$name}" => config('database.connections.pgsql')]);
    DB::purge($name);
    $connection = DB::connection($name);
    $connection->statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);

    return $connection;
}

it('never gives a number twice nor leaves a gap under concurrent validations (Review Focus 3)', function () {
    // Committed fixtures (the per-test transaction is invisible to other sessions), removed in finally.
    $setup = fvConnection('fv_setup', '');
    $tenantId = (string) Str::uuid7();
    $companyId = (string) Str::uuid7();
    $setup->table('tenants')->insert(['id' => $tenantId, 'name' => 'Factures', 'slug' => 'invoices-'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()]);
    $setup->statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);
    $setup->table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenantId, 'name' => 'Société facturante', 'created_at' => now(), 'updated_at' => now()]);

    $a = fvConnection('fv_a', $tenantId);
    $b = fvConnection('fv_b', $tenantId);
    $company = fn (string $connection) => (new Company)->newFromBuilder(['id' => $companyId, 'tenant_id' => $tenantId, 'invoice_prefix' => 'F', 'credit_note_prefix' => 'AV'], $connection);
    $numbering = new InvoiceNumbering;
    $at = CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris');

    try {
        // Outside a transaction the row lock would be released at once.
        expect(fn () => $numbering->next($company('fv_a'), Invoice::INVOICE, $at))->toThrow(LogicException::class);

        $a->beginTransaction();
        $b->beginTransaction();
        expect($numbering->next($company('fv_a'), Invoice::INVOICE, $at))->toBe('F-2026-00001');

        // B waits for A's row lock (a short lock timeout makes it give up instead of reading the same number).
        $b->statement("set local lock_timeout = '300ms'");
        $blocked = null;
        try {
            $numbering->next($company('fv_b'), Invoice::INVOICE, $at);
        } catch (QueryException $e) {
            $blocked = $e;
        }
        expect($blocked?->getCode())->toBe('55P03');
        $b->rollBack();

        // Another kind is not held by A.
        $b->beginTransaction();
        expect($numbering->next($company('fv_b'), Invoice::CREDIT_NOTE, $at))->toBe('AV-2026-00001');
        $b->commit();

        // A rolls back: its number is given again, no gap; then B goes on.
        $a->rollBack();
        $b->beginTransaction();
        expect($numbering->next($company('fv_b'), Invoice::INVOICE, $at))->toBe('F-2026-00001');
        $b->commit();
        $a->beginTransaction();
        expect($numbering->next($company('fv_a'), Invoice::INVOICE, $at))->toBe('F-2026-00002');
        $a->commit();
    } finally {
        foreach ([$a, $b] as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        DB::connection('pgsql_owner')->table('tenants')->where('id', $tenantId)->delete();
        foreach (['fv_setup', 'fv_a', 'fv_b'] as $name) {
            DB::purge($name);
        }
    }
});

it('gives the number back when the PDF cannot be rendered: nothing is validated', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fvDraft($x, 'contractor', $user);
    Http::swap(new Factory);
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);

    expect(fn () => fvValidate($draft, $user))->toThrow(PdfRenderingFailed::class);
    expect($draft->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and(InvoiceJournalEntry::query()->count())->toBe(0)
        ->and(app(BillingPlans::class)->isLocked($x['dossier']->id))->toBeFalse();

    Http::swap(new Factory);
    fvGotenberg();
    expect(fvValidate($draft, $user)->number)->toBe('F-2026-00001');
});

// ---------------------------------------------------------------- refusals

it('refuses a funder invoice without the agreement number of its funding', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fvDraft($x, 'funding:'.$x['funding']->id, $user);
    Funding::query()->whereKey($x['funding']->id)->update(['agreement_number' => '  ']);

    try {
        fvValidate($draft, $user);
        $this->fail('validated without an agreement number');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['invoice' => [InvoiceValidator::NO_AGREEMENT]]);
    }
    expect($draft->refresh()->isDraft())->toBeTrue();

    Funding::query()->whereKey($x['funding']->id)->update(['agreement_number' => 'PEC-42']);
    expect(fvValidate($draft, $user)->number)->toBe('F-2026-00001');
});

it('refuses a draft that bills more than is due now, or a recipient no longer invoiceable', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $contractor = fvDraft($x, 'contractor', $user);
    $opco = fvDraft($x, 'funding:'.$x['funding']->id, $user);

    // Léa was absent on the first séance after all: less is due than the drafts bill.
    fiLateAttendance($x, 0, 'absent');
    expect(fn () => fvValidate($contractor, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::DUE_CHANGED);
    fiDrafts()->recompute($contractor);
    $due = fiDue($x['dossier']->id)['contractor']['total_cents'];
    expect($due)->toBeLessThan(17143)
        ->and(fvValidate($contractor->refresh(), $user)->total_ht_cents)->toBe($due);

    // The OPCO no longer pays directly (changed before the lock): its draft can't be validated.
    Funding::query()->whereKey($x['funding']->id)->update(['pays_directly' => false]);
    expect(fn () => fvValidate($opco, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::NOT_INVOICEABLE);
});

it('refuses an invoice whose total is not positive', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fiDrafts()->manual($user, $x['dossier']->id, 'contractor');
    $draft->lines()->get()->each(fn (InvoiceLine $l) => $l->delete());

    expect(fn () => fvValidate($draft, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::NOT_POSITIVE);
});

// ---------------------------------------------------------------- credit notes

it('creates a credit note from a validated invoice, numbered AV, and the due is computed again', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'contractor', $user), $user);

    $credit = app(CreditNotes::class)->create($user, $invoice);
    expect($credit->kind)->toBe(Invoice::CREDIT_NOTE)
        ->and($credit->isDraft())->toBeTrue()
        ->and($credit->corrected_invoice_id)->toBe($invoice->id)
        ->and($credit->funding_id)->toBeNull()
        ->and($credit->total_ht_cents)->toBe(-17143)
        ->and($credit->lines()->get()->map->only(['kind', 'component', 'unit_price_cents', 'total_ht_cents', 'computed_cents'])->all())
        ->toBe([['kind' => 'computed', 'component' => 'training', 'unit_price_cents' => -17143, 'total_ht_cents' => -17143, 'computed_cents' => -17143]]);

    // One open credit-note draft per recipient.
    expect(fn () => app(CreditNotes::class)->create($user, $invoice))->toThrow(UserFacingHttpException::class, CreditNotes::DRAFT_OPEN);

    $validated = fvValidate($credit, $user);
    expect($validated->number)->toBe('AV-2026-00001')
        ->and($validated->due_on)->not->toBeNull()
        ->and(fiDue($x['dossier']->id)['contractor']['total_cents'])->toBe(17143);

    // Nothing left to give back to the contractor.
    $again = app(CreditNotes::class)->create($user, $invoice);
    expect(fn () => fvValidate($again, $user))->toThrow(UserFacingHttpException::class, 'Cet avoir dépasse le montant restant de la facture F-2026-00001.');

    // An invoice draft and a credit-note draft of the same recipient live together.
    fvDraft($x, 'contractor', $user);
    expect(Invoice::query()->where('dossier_id', $x['dossier']->id)->where('status', 'draft')->pluck('kind')->sort()->values()->all())
        ->toBe(['credit_note', 'invoice']);

    // A credit note is never made from a draft nor from a credit note.
    expect(fn () => app(CreditNotes::class)->create($user, $validated))->toThrow(UserFacingHttpException::class, CreditNotes::NOT_AN_INVOICE);
});

it('gives back the chosen lines only, never more than was invoiced', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fvDraft($x, 'contractor', $user);
    fiDrafts()->update($draft, ['notes' => null, 'lines' => [
        ...$draft->lines()->get()->map(fn (InvoiceLine $l) => ['id' => $l->id, 'label' => $l->label, 'description' => $l->description, 'quantity' => '1', 'unit' => $l->unit, 'unit_price' => '171,43', 'vat_rate' => '0'])->all(),
        ['label' => 'Repas', 'description' => null, 'quantity' => '2', 'unit' => 'forfait', 'unit_price' => '15', 'vat_rate' => '0'],
    ]]);
    $invoice = fvValidate($draft, $user);
    $meals = $invoice->lines()->where('kind', 'free')->sole();

    expect(fn () => app(CreditNotes::class)->create($user, $invoice, []))->toThrow(ValidationException::class);
    $credit = app(CreditNotes::class)->create($user, $invoice, [$meals->id]);
    expect($credit->total_ht_cents)->toBe(-3000);

    // Raised beyond what the invoice billed: refused.
    $line = $credit->lines()->sole();
    fiDrafts()->update($credit, ['notes' => null, 'lines' => [['id' => $line->id, 'label' => 'Repas', 'description' => null, 'quantity' => '2', 'unit' => 'forfait', 'unit_price' => '-200', 'vat_rate' => '0']]]);
    expect(fn () => fvValidate($credit->refresh(), $user))->toThrow(UserFacingHttpException::class, 'Cet avoir dépasse le montant restant de la facture F-2026-00001.');
});

/**
 * The contractor's invoice F-2026-00001: the training line (171,43 €, computed) and two meals (30 €, free).
 */
function fvInvoiceWithMeals(array $x, User $user): Invoice
{
    $draft = fvDraft($x, 'contractor', $user);
    fiDrafts()->update($draft, ['notes' => null, 'lines' => [
        ...$draft->lines()->get()->map(fn (InvoiceLine $l) => ['id' => $l->id, 'label' => $l->label, 'description' => $l->description, 'quantity' => '1', 'unit' => $l->unit, 'unit_price' => '171,43', 'vat_rate' => '0'])->all(),
        ['label' => 'Repas', 'description' => null, 'quantity' => '2', 'unit' => 'forfait', 'unit_price' => '15', 'vat_rate' => '0'],
    ]]);

    return fvValidate($draft, $user);
}

/** Sets the unit prices of the credit-note draft, in the order of its lines. */
function fvCreditPrices(Invoice $credit, array $prices): Invoice
{
    fiDrafts()->update($credit, ['notes' => null, 'lines' => $credit->lines()->get()->values()->map(fn (InvoiceLine $l, int $i) => [
        'id' => $l->id, 'label' => $l->label, 'description' => $l->description, 'quantity' => '1', 'unit' => $l->unit, 'unit_price' => $prices[$i], 'vat_rate' => '0',
    ])->all()]);

    return $credit->refresh();
}

it('never gives back more than is left of the corrected invoice, net of its earlier credit notes', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvInvoiceWithMeals($x, $user);
    expect($invoice->total_ht_cents)->toBe(20143);

    // A first credit note of 100 € on the training.
    $first = fvCreditPrices(app(CreditNotes::class)->create($user, $invoice, [$invoice->lines()->where('kind', 'computed')->sole()->id]), ['-100']);
    expect(fvValidate($first, $user)->number)->toBe('AV-2026-00001');

    // The whole invoice again: more than the 101,43 € left.
    $second = app(CreditNotes::class)->create($user, $invoice);
    expect($second->total_ht_cents)->toBe(-20143)
        ->and(fn () => fvValidate($second, $user))->toThrow(UserFacingHttpException::class, 'Cet avoir dépasse le montant restant de la facture F-2026-00001.');

    // Exactly what is left goes through.
    expect(fvValidate(fvCreditPrices($second, ['-71,43', '-30']), $user)->total_ht_cents)->toBe(-10143);
});

it('keeps the computed lines of a credit note within what the dossier invoiced to the recipient', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvInvoiceWithMeals($x, $user);

    // 190 € on the training (171,43 € invoiced), the meals at 0: within the invoice, beyond the computed amount.
    $credit = fvCreditPrices(app(CreditNotes::class)->create($user, $invoice), ['-190', '0']);
    expect(fn () => fvValidate($credit, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::OVER_INVOICED);
});

it('gives a credit note the VAT regime of the invoice it corrects, not the company\'s of today', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'contractor', $user), $user);
    expect($invoice->vat_mention)->toBe('Exonération de TVA, art. 261, 4-4° a du CGI');

    // The company became subject to VAT since.
    Company::query()->whereKey($invoice->company_id)->update(['vat_regime' => 'subject']);
    $credit = app(CreditNotes::class)->create($user, $invoice);
    $credit = fvCreditPrices($credit, ['-171,43']);
    fiDrafts()->update($credit, ['notes' => null, 'lines' => $credit->lines()->get()->map(fn (InvoiceLine $l) => [
        'id' => $l->id, 'label' => $l->label, 'description' => null, 'quantity' => '1', 'unit' => $l->unit, 'unit_price' => '-171,43', 'vat_rate' => '20',
    ])->all()]);
    $credit = fvValidate($credit->refresh(), $user);

    expect($credit->total_vat_cents)->toBe(0)
        ->and($credit->vat_mention)->toBe('Exonération de TVA, art. 261, 4-4° a du CGI')
        ->and($credit->lines()->pluck('vat_rate')->map(fn ($r) => (string) $r)->all())->toBe(['0.00'])
        ->and(fvSentText())->toContain('Exonération de TVA, art. 261, 4-4° a du CGI')->not->toContain('Taux de TVA');
});

// ---------------------------------------------------------------- the dossier

it('marks the dossier invoiced once nothing is due, and back after a credit note', function () {
    $x = fiSeed();
    $user = fvAccountant();
    // Stored « created »: « Réalisé » is computed from the dates of its sessions (Billing::dossier gives the effective state).
    expect(Dossier::query()->findOrFail($x['dossier']->id)->status->value)->toBe('created');
    fvValidate(fvDraft($x, 'contractor', $user), $user);
    expect(Dossier::query()->findOrFail($x['dossier']->id)->status->value)->toBe('created');

    $opco = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    expect(Dossier::query()->findOrFail($x['dossier']->id)->status->value)->toBe('invoiced');

    fvValidate(app(CreditNotes::class)->create($user, $opco), $user);
    expect(Dossier::query()->findOrFail($x['dossier']->id)->status->value)->toBe('done');
});

// ---------------------------------------------------------------- immutability

it('freezes a validated invoice, its lines and the journal in the database (Review Focus 4)', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'contractor', $user), $user);
    $line = $invoice->lines()->firstOrFail();

    expect(fvRefused(fn () => $invoice->forceFill(['total_ht_cents' => 1])->save()))->toContain('immutable')
        ->and(fvRefused(fn () => DB::update('update invoices set notes = ? where id = ?', ['changé', $invoice->id])))->toContain('immutable')
        ->and(fvRefused(fn () => DB::update('update invoices set number = ? where id = ?', ['F-2026-00009', $invoice->id])))->toContain('immutable')
        ->and(fvRefused(fn () => DB::update('update invoices set status = ? where id = ?', ['draft', $invoice->id])))->toContain('immutable')
        ->and(fvRefused(fn () => DB::delete('delete from invoices where id = ?', [$invoice->id])))->toContain('immutable')
        ->and(fvRefused(fn () => $line->forceFill(['total_ht_cents' => 1])->save()))->toContain('immutable')
        ->and(fvRefused(fn () => DB::delete('delete from invoice_lines where id = ?', [$line->id])))->toContain('immutable')
        ->and(fvRefused(fn () => InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => 9, 'kind' => 'free', 'label' => 'Ajout', 'quantity' => '1', 'unit' => 'forfait',
            'unit_price_cents' => 100, 'vat_rate' => '0', 'total_ht_cents' => 100,
        ])))->toContain('immutable')
        ->and(fvRefused(fn () => DB::update('update invoice_journal set hash = ? where invoice_id = ?', [str_repeat('0', 64), $invoice->id])))->toContain('append-only')
        ->and(fvRefused(fn () => DB::delete('delete from invoice_journal where invoice_id = ?', [$invoice->id])))->toContain('append-only');

    // Sending is the one change left: validated → sent, sent_at and the e-mail status.
    expect(fvRefused(fn () => DB::update(
        'update invoices set status = ?, sent_at = now(), email_status = ?, updated_at = now() where id = ?',
        ['sent', 'sent', $invoice->id],
    )))->toBeNull()
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and(fvRefused(fn () => DB::update('update invoices set status = ? where id = ?', ['validated', $invoice->id])))->toContain('immutable');

    // A draft stays free.
    $opco = fvDraft($x, 'funding:'.$x['funding']->id, $user);
    expect(fvRefused(fn () => DB::update('update invoices set notes = ? where id = ?', ['brouillon', $opco->id])))->toBeNull()
        ->and(fvRefused(fn () => fiDrafts()->delete($opco)))->toBeNull()
        ->and(Integrity::verify())->toBe(['count' => 1, 'broken' => null]);
});

// ---------------------------------------------------------------- PDF

it('prints every mandatory mention, the funding and the VAT exemption', function () {
    $x = fiSeed();
    fvIdentities();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    $text = fvSentText();

    foreach ([
        'CAP FORMATION', 'SIRET 732 829 320 00074', 'N° TVA intracommunautaire : FR40732829320',
        "Déclaration d'activité enregistrée sous le n° 84740123474. Cet enregistrement ne vaut pas agrément de l'État.",
        'FACTURE', 'F-2026-00001', "Date d'émission 17/11/2026", "Date d'échéance 17/12/2026",
        'OPCO EP', 'Dossier : '.$x['dossier']->number, "Financement — n° d'accord : PEC-42",
        'Période de formation : du 01/09/2026 au 30/09/2026',
        'HACCP 3 jours — du 01/09/2026 au 30/09/2026 — 9 h réalisées / 21 h prévues', 'Stagiaires : Léa Durand',
        'Prix unitaire HT', '257,14 €', 'Total HT', 'Net à payer',
        'Exonération de TVA, art. 261, 4-4° a du CGI',
        'Paiement à 30 jours, au plus tard le 17/12/2026.', "Pas d'escompte pour paiement anticipé.",
        'pénalités de retard', 'indemnité forfaitaire pour frais de recouvrement de 40 €', 'IBAN FR76 1234 5678 9012',
    ] as $mention) {
        expect($text)->toContain($mention);
    }
    expect($text)->not->toContain('BROUILLON')
        ->and($invoice->recipient_snapshot['agreement_number'])->toBe('PEC-42');

    // The contractor's: its SIREN and address.
    fvValidate(fvDraft($x, 'contractor', $user), $user);
    expect(fvSentText())->toContain('Hôtel du Parc')->toContain('1 quai du Lac')->toContain('74000 Annecy')->toContain('SIREN 412345678');
});

it('prints a credit note with the invoice it corrects, and the VAT of a company subject to it', function () {
    $x = fiSeed(vat: 'subject');
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'contractor', $user), $user);
    expect(fvSentText())->toContain('Taux de TVA')->toContain('20 %')->toContain('Total TTC')->toContain('205,72 €')
        ->not->toContain('Exonération');

    fvValidate(app(CreditNotes::class)->create($user, $invoice), $user);
    expect(fvSentText())->toContain('AVOIR')->toContain('AV-2026-00001')->toContain('Avoir sur la facture F-2026-00001 du 17/11/2026')
        ->toContain('-171,43 €');
});

it('serves the archive of a validated invoice, never a new rendering; previews a draft', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fvDraft($x, 'contractor', $user);
    $this->actingAs($user);

    $preview = $this->get("/finance/invoices/{$draft->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($preview->streamedContent())->toBe('%PDF-1.7 facture 1')
        ->and(fvSentText())->toContain('BROUILLON');

    $invoice = fvValidate($draft, $user);
    $response = $this->get("/finance/invoices/{$invoice->id}/pdf")->assertOk();
    expect($response->streamedContent())->toBe('%PDF-1.7 facture 2')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="F-2026-00001.pdf"');
    Http::assertSentCount(2);

    Storage::disk(TenantStorage::DISK)->delete((string) $invoice->pdf_path);
    $this->get("/finance/invoices/{$invoice->id}/pdf")->assertStatus(410);
    Http::assertSentCount(2);
});

it('renders the mandatory mentions in a real PDF read back by pdftotext', function () {
    Http::swap(new Factory);
    config(['documents.gotenberg_url' => getenv('GOTENBERG_URL') ?: 'http://gotenberg:3000']);
    $x = fiSeed();
    fvIdentities();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);

    $file = tempnam(sys_get_temp_dir(), 'invoice-');
    file_put_contents($file, Storage::disk(TenantStorage::DISK)->get((string) $invoice->pdf_path));
    $result = Process::run(['pdftotext', '-layout', '-enc', 'UTF-8', $file, '-']);
    unlink($file);
    expect($result->successful())->toBeTrue();
    $text = fvText($result->output());

    foreach ([
        'FACTURE', 'F-2026-00001', 'CAP FORMATION', '732 829 320 00074', 'FR40732829320', '84740123474',
        'OPCO EP', 'PEC-42', $x['dossier']->number, '17/11/2026', '17/12/2026', '01/09/2026', '30/09/2026',
        'Stagiaires : Léa Durand', '257,14 €', 'Exonération de TVA, art. 261, 4-4° a du CGI', '40 €', 'pénalités de retard',
    ] as $mention) {
        expect($text)->toContain($mention);
    }
})->group('slow');

// ---------------------------------------------------------------- pages and rights

it('validates from the page for who holds the right, within the scope of the dossier', function () {
    $x = fiSeed();
    $manager = fiManager();
    $draft = fvDraft($x, 'contractor', $manager);

    $this->actingAs($manager)->get("/finance/invoices/{$draft->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.validate', false)->where('can.update', true)->where('can.credit_note', false));
    $this->post("/finance/invoices/{$draft->id}/validate")->assertForbidden();

    // « Ses fiches » only: a dossier of someone else is out of reach.
    $own = fiManager([Permissions::VALIDATE => AccessScope::Own, Permissions::VIEW => AccessScope::Own, SessionsPermissions::DOSSIERS_VIEW => AccessScope::Own]);
    $this->actingAs($own)->post("/finance/invoices/{$draft->id}/validate")->assertNotFound();

    $accountant = fvAccountant();
    $this->actingAs($accountant)->get("/finance/invoices/{$draft->id}")->assertInertia(fn (Assert $page) => $page->where('can.validate', true));
    $this->from("/finance/invoices/{$draft->id}")->post("/finance/invoices/{$draft->id}/validate")
        ->assertRedirect("/finance/invoices/{$draft->id}")->assertSessionHas('status', 'Facture validée : F-2026-00001.');
    $this->get("/finance/invoices/{$draft->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.validate', false)->where('can.update', false)->where('can.credit_note', true)->where('invoice.number', 'F-2026-00001'));

    // A second click answers the French refusal.
    $this->post("/finance/invoices/{$draft->id}/validate")->assertStatus(409);

    // « Créer un avoir »: the chosen lines, then the draft.
    $line = Invoice::query()->findOrFail($draft->id)->lines()->firstOrFail();
    $response = $this->post("/finance/invoices/{$draft->id}/credit-note", ['lines' => [$line->id]]);
    $credit = Invoice::query()->where('kind', 'credit_note')->sole();
    $response->assertRedirect("/finance/invoices/{$credit->id}")->assertSessionHas('status', 'Brouillon d\'avoir prêt.');
    $this->from("/finance/invoices/{$credit->id}")->post("/finance/invoices/{$credit->id}/validate")
        ->assertSessionHas('status', 'Avoir validé : AV-2026-00001.');

    // Without the right to manage: no credit note.
    $reader = l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]);
    $this->actingAs($reader)->post("/finance/invoices/{$draft->id}/credit-note")->assertForbidden();
    $this->get("/finance/invoices/{$draft->id}/pdf")->assertOk();
});

// ---------------------------------------------------------------- personal data

it('keeps validated invoices untouched by an anonymisation, deleting the drafts naming the person', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    $draft = fvDraft($x, 'contractor', $user);
    $before = Integrity::canonical($invoice);
    expect($before)->toContain('Léa Durand');

    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($x['lea']->person_id), 'request');

    expect(Invoice::query()->find($draft->id))->toBeNull()
        ->and(InvoiceLine::query()->where('invoice_id', $draft->id)->count())->toBe(0)
        ->and(Integrity::canonical($invoice->refresh()))->toBe($before)
        ->and(Integrity::verify())->toBe(['count' => 1, 'broken' => null]);
});

it('deletes the drafts addressed to an anonymised particulier', function () {
    $person = makePerson('Martin', ['first_name' => 'Paul', 'is_individual' => true]);
    $dossier = l2Dossier($person);
    $session = plSession('2026-09-01', '2026-09-30');
    l2Enrol($dossier, $session, $person);
    $user = fvAccountant();
    $draft = fiDrafts()->manual($user, $dossier->id, 'contractor');
    expect($draft->recipient_type)->toBe('person');

    app(Anonymiser::class)->anonymise($person, 'request');

    expect(Invoice::query()->find($draft->id))->toBeNull();
});

it('prints the late penalties and the 40 € indemnity for a professional only, never for a particulier', function () {
    $person = makePerson('Martin', ['first_name' => 'Paul', 'is_individual' => true, 'address_line1' => '3 rue des Lilas', 'postal_code' => '74000', 'city' => 'Annecy']);
    $dossier = l2Dossier($person);
    l2Enrol($dossier, plSession('2026-09-01', '2026-09-30'), $person);
    $user = fvAccountant();
    $draft = fiDrafts()->manual($user, $dossier->id, 'contractor');
    fiDrafts()->update($draft, ['notes' => null, 'lines' => [['label' => 'Frais de dossier', 'description' => null, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '50', 'vat_rate' => '0']]]);

    fvValidate($draft->refresh(), $user);
    $text = fvSentText();
    expect($text)->toContain('Paul Martin')->toContain('Paiement à 30 jours')
        ->not->toContain('40 €')->not->toContain('pénalités');
});

it('never writes a line onto an invoice it cannot see (another organisme)', function () {
    $x = fiSeed();
    $draft = fiDrafts()->manual(fvAccountant(), $x['dossier']->id, 'contractor');

    inTenant(Tenant::factory()->create());
    expect(fvRefused(fn () => InvoiceLine::query()->create([
        'invoice_id' => $draft->id, 'position' => 0, 'kind' => 'free', 'label' => 'Intrus', 'quantity' => '1', 'unit' => 'forfait',
        'unit_price_cents' => 100, 'vat_rate' => '0', 'total_ht_cents' => 100,
    ])))->toContain('not found');
});

/**
 * A dossier of 3 500 € on 21 h, monthly: Léa present 6 h in September and
 * 6 h in October, each month billed 1 000 € (F-2026-00001, F-2026-00002;
 * their training line at $rates[0], $rates[1] when the company is subject
 * to VAT).
 *
 * @param  array{0: ?string, 1: ?string}  $rates
 * @return array<string, mixed>
 */
function fvTwoMonths(string $vat = 'exempt', array $rates = [null, null]): array
{
    $dossier = l2Dossier(null, null, ['lines' => [[
        'kind' => 'training', 'training_id' => l2Training()->id, 'label' => 'HACCP 3 jours', 'quantity' => '1',
        'unit' => 'forfait', 'unit_price' => '3500', 'discount_percent' => '', 'pricing_mode' => 'per_trainee',
    ]]]);
    Company::query()->whereKey($dossier->company_id)->update(['vat_regime' => $vat]);
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $seances = [];
    foreach (['2026-09-02', '2026-09-03', '2026-10-01', '2026-10-02'] as $day) {
        $seances[] = $seance = plSeance($module, $day, '09:00', '12:00');
        app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    }
    app(Closures::class)->close($session->refresh(), '2026-09');
    app(Closures::class)->close($session->refresh(), '2026-10');
    app(BillingPlans::class)->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised']);
    $user = fvAccountant();
    $x = ['dossier' => $dossier, 'session' => $session, 'seances' => $seances, 'lea' => $lea, 'user' => $user];

    $invoices = [];
    foreach ($rates as $rate) {
        $draft = fvDraft($x, 'contractor', $user);
        if ($rate !== null) {
            fiDrafts()->update($draft, ['notes' => null, 'lines' => $draft->lines()->get()->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'label' => $l->label, 'description' => $l->description, 'quantity' => '1', 'unit' => $l->unit, 'unit_price' => '1000', 'vat_rate' => $rate,
            ])->all()]);
        }
        $invoices[] = fvValidate($draft->refresh(), $user);
    }

    return [...$x, 'f1' => $invoices[0], 'f2' => $invoices[1]];
}

/** Léa was absent but for one séance: 3 h realised, 500 € in all, −1 500 € to give back. */
function fvHoursDrop(array $x): void
{
    foreach (['2026-09', '2026-10'] as $month) {
        app(Closures::class)->reopen($x['session']->refresh(), $month, 'Feuilles corrigées');
    }
    foreach ([0, 1, 2] as $i) {
        app(Attendances::class)->record($x['seances'][$i], $x['lea']->id, 'absent', null, null);
    }
    foreach (['2026-09', '2026-10'] as $month) {
        app(Closures::class)->close($x['session']->refresh(), $month);
    }
}

function fvCreditDraft(array $x): Invoice
{
    return Invoice::query()->where('dossier_id', $x['dossier']->id)->where('kind', 'credit_note')->where('status', 'draft')->sole();
}

it('splits a proposed credit note over the invoices it corrects, never beyond what is left of each', function () {
    $x = fvTwoMonths();
    [$dossier, $user, $f1, $f2] = [$x['dossier'], $x['user'], $x['f1'], $x['f2']];
    expect([$f1->number, $f1->total_ht_cents, $f2->number, $f2->total_ht_cents])->toBe(['F-2026-00001', 100000, 'F-2026-00002', 100000]);
    fvHoursDrop($x);

    $row = fiDue($dossier->id)['contractor'];
    expect($row)->toMatchArray(['kind' => 'credit_note', 'total_cents' => -100000, 'unsettled_cents' => -50000, 'corrected_invoice_id' => $f2->id, 'preparable' => true]);
    fiDrafts()->prepare($user, [$row['key']]);
    $first = fvValidate(fvCreditDraft($x), $user);
    expect([$first->number, $first->total_ht_cents, $first->corrected_invoice_id])->toBe(['AV-2026-00001', -100000, $f2->id]);

    $row = fiDue($dossier->id)['contractor'];
    expect($row)->toMatchArray(['kind' => 'credit_note', 'total_cents' => -50000, 'unsettled_cents' => 0, 'corrected_invoice_id' => $f1->id]);
    fiDrafts()->prepare($user, [$row['key']]);
    $second = fvValidate(fvCreditDraft($x), $user);
    expect([$second->number, $second->total_ht_cents, $second->corrected_invoice_id])->toBe(['AV-2026-00002', -50000, $f1->id])
        ->and(fiDue($dossier->id))->toBe([])
        ->and(Integrity::verify())->toBe(['count' => 4, 'broken' => null]);
});

it('gives back each proposed credit-note line at the VAT rate of the corrected invoice, not editable', function () {
    $x = fvTwoMonths('subject', ['10', '10']);
    fvHoursDrop($x);
    fiDrafts()->prepare($x['user'], [fiDue($x['dossier']->id)['contractor']['key']]);
    $draft = fvCreditDraft($x);
    $line = $draft->lines()->sole();
    expect((string) $line->vat_rate)->toBe('10.00');

    // Changed to 20 % in the form: the rate of the corrected invoice stays.
    fiDrafts()->update($draft, ['notes' => null, 'lines' => [[
        'id' => $line->id, 'label' => $line->label, 'description' => null, 'quantity' => '1', 'unit' => $line->unit, 'unit_price' => '-1000', 'vat_rate' => '20',
    ]]]);
    expect((string) $line->refresh()->vat_rate)->toBe('10.00');

    $credit = fvValidate($draft->refresh(), $x['user']);
    expect([$credit->total_ht_cents, $credit->total_vat_cents, $credit->corrected_invoice_id])->toBe([-100000, -10000, $x['f2']->id]);
});

it('recalculates a credit-note draft onto the invoice now left to correct, with its VAT rate', function () {
    $x = fvTwoMonths('subject', ['10', '20']);
    fvHoursDrop($x);
    fiDrafts()->prepare($x['user'], [fiDue($x['dossier']->id)['contractor']['key']]);
    $draft = fvCreditDraft($x);
    expect($draft->corrected_invoice_id)->toBe($x['f2']->id)
        ->and((string) $draft->lines()->sole()->vat_rate)->toBe('20.00');

    // Meanwhile F-2026-00002 was fully credited (an earlier credit note, validated).
    DB::table('invoices')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => tenant()->id, 'company_id' => $x['f2']->company_id, 'kind' => 'credit_note', 'number' => 'AV-2026-00009',
        'status' => 'validated', 'dossier_id' => $x['dossier']->id, 'recipient_type' => $x['f2']->recipient_type, 'recipient_id' => $x['f2']->recipient_id,
        'issued_on' => '2026-11-17', 'rhythm' => 'monthly', 'basis' => 'realised', 'total_ht_cents' => -100000, 'corrected_invoice_id' => $x['f2']->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $draft = fiDrafts()->recompute($draft)->refresh();
    expect($draft->corrected_invoice_id)->toBe($x['f1']->id)
        ->and((string) $draft->lines()->sole()->vat_rate)->toBe('10.00')
        ->and($draft->total_vat_cents)->toBe(-10000);
});

it('proposes nothing to prepare when no invoice is left to correct, and never validates a credit note without one', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvInvoiceWithMeals($x, $user);
    // The whole invoice credited on its free line: nothing left to correct, the computed amount still invoiced.
    fvValidate(fvCreditPrices(app(CreditNotes::class)->create($user, $invoice, [$invoice->lines()->where('kind', 'free')->sole()->id]), ['-201,43']), $user);
    fiLateAttendance($x, 0, 'absent');

    $row = fiDue($x['dossier']->id)['contractor'];
    expect($row)->toMatchArray(['kind' => 'credit_note', 'corrected_invoice_id' => null, 'preparable' => false, 'blocked_reason' => 'À régulariser : aucune facture à corriger'])
        ->and(fiDrafts()->prepare($user, [$row['key']]))->toBe(['created' => 0, 'skipped' => 1]);
    $this->actingAs(fiManager())->get('/finance/to-invoice')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.preparable', false)->where('rows.0.blocked_reason', 'À régulariser : aucune facture à corriger'));

    // A credit note always corrects a validated invoice of its recipient.
    $opco = fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    $credit = app(CreditNotes::class)->create($user, $opco);
    $credit->forceFill(['corrected_invoice_id' => $invoice->id])->save();
    expect(fn () => fvValidate($credit, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::NO_CORRECTED)
        ->and(fvRefused(fn () => $credit->forceFill(['corrected_invoice_id' => null])->save()))->toContain('invoices_credit_note_corrects_check');
});

// ---------------------------------------------------------------- the parties of a credit note

/** The form of $dossier as stored (its lines by id), with $overrides. */
function fvDossierPayload(Dossier $dossier, array $overrides = []): array
{
    return [
        'company_id' => $dossier->company_id, 'account_organisation_id' => $dossier->account_organisation_id,
        'account_person_id' => $dossier->account_person_id, 'das_id' => $dossier->das_id, 'notes' => $dossier->notes,
        'lines' => $dossier->lines()->get()->map(fn ($l) => [
            'id' => $l->id, 'kind' => $l->kind, 'training_id' => $l->training_id, 'label' => $l->label, 'quantity' => (string) $l->quantity,
            'unit' => $l->unit, 'unit_price' => (string) $l->unit_price, 'discount_percent' => (string) $l->discount_percent, 'pricing_mode' => $l->pricing_mode,
        ])->all(),
        ...$overrides,
    ];
}

it('addresses a credit note from the company to the recipient of the invoice it corrects, even once changed on the dossier', function () {
    $x = fiSeed();
    fvIdentities();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'contractor', $user), $user);
    $hotel = $invoice->recipient_id;
    // Changed behind the guards (fiches merged, data fixed by hand): another company and contractor.
    $other = Company::factory()->create(['invoice_prefix' => 'FB', 'credit_note_prefix' => 'AVB']);
    $newcomer = Organisation::query()->create(['name' => 'Nouvelle SA']);
    Dossier::query()->whereKey($x['dossier']->id)->update(['company_id' => $other->id, 'account_organisation_id' => $newcomer->id]);
    fiLateAttendance($x, 0, 'absent');

    $row = fiDue($x['dossier']->id)['contractor'];
    expect($row)->toMatchArray(['kind' => 'credit_note', 'corrected_invoice_id' => $invoice->id, 'company_id' => $invoice->company_id])
        ->and($row['recipient'])->toMatchArray(['type' => 'organisation', 'id' => $hotel, 'label' => 'Hôtel du Parc']);

    fiDrafts()->prepare($user, [$row['key']]);
    $credit = fvCreditDraft($x);
    $parties = ['company_id' => $invoice->company_id, 'recipient_type' => 'organisation', 'recipient_id' => $hotel];
    expect($credit->only(array_keys($parties)))->toBe($parties);

    // Recalculated: back to the parties of the corrected invoice.
    $credit->forceFill(['company_id' => $other->id, 'recipient_id' => $newcomer->id])->save();
    $credit = fiDrafts()->recompute($credit)->refresh();
    expect($credit->only(array_keys($parties)))->toBe($parties);

    $validated = fvValidate($credit, $user);
    expect($validated->number)->toBe('AV-2026-00001')
        ->and($validated->recipient_snapshot['name'])->toBe('Hôtel du Parc');
});

it('refuses a credit note issued by another company or to another recipient than the invoice it corrects', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $invoice = fvValidate(fvDraft($x, 'contractor', $user), $user);
    $credit = app(CreditNotes::class)->create($user, $invoice);
    $other = Company::factory()->create(['invoice_prefix' => 'FB', 'credit_note_prefix' => 'AVB']);

    $credit->forceFill(['company_id' => $other->id])->save();
    expect(fn () => fvValidate($credit, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::OTHER_PARTIES);
    $credit->forceFill(['company_id' => $invoice->company_id, 'recipient_id' => Organisation::query()->create(['name' => 'Autre SA'])->id])->save();
    expect(fn () => fvValidate($credit, $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::OTHER_PARTIES);
    expect(InvoiceValidator::OTHER_PARTIES)->toBe('Un avoir doit être émis par la même société et au même destinataire que la facture corrigée.');

    $credit->forceFill(['recipient_id' => $invoice->recipient_id])->save();
    expect(fvValidate($credit, $user)->number)->toBe('AV-2026-00001');
});

it('refuses to change the company or the contractor of an invoiced dossier, and the funder of an invoiced funding', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $dossiers = app(DossierWriter::class);
    $fundings = app(FundingWriter::class);
    $other = Company::factory()->create(['invoice_prefix' => 'FB', 'credit_note_prefix' => 'AVB']);
    $newcomer = Organisation::query()->create(['name' => 'Nouvelle SA']);
    $region = Organisation::query()->create(['name' => 'Région']);
    $second = l2Funding($x['dossier'], ['amount' => '100,00']);
    $refusal = function (Closure $change): array {
        try {
            $change();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    // Not invoiced yet: free to change (and back).
    $dossier = Dossier::query()->findOrFail($x['dossier']->id);
    $dossiers->update($dossier, fvDossierPayload($dossier, ['company_id' => $other->id]));
    $dossier = $dossier->refresh();
    $dossiers->update($dossier, fvDossierPayload($dossier, ['company_id' => $x['dossier']->company_id]));
    $fundings->update($second, ['funder_organisation_id' => $region->id]);

    fvValidate(fvDraft($x, 'contractor', $user), $user);
    fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    expect(BillingPlans::HAS_INVOICES)->toBe('Ce dossier a des factures validées : faites d\'abord un avoir.');

    $dossier = Dossier::query()->findOrFail($x['dossier']->id);
    expect($refusal(fn () => $dossiers->update($dossier, fvDossierPayload($dossier, ['company_id' => $other->id]))))
        ->toBe(['company_id' => [BillingPlans::HAS_INVOICES]])
        ->and($refusal(fn () => $dossiers->update($dossier, fvDossierPayload($dossier, ['account_organisation_id' => $newcomer->id]))))
        ->toBe(['account' => [BillingPlans::HAS_INVOICES]])
        ->and($refusal(fn () => $fundings->update(Funding::query()->findOrFail($x['funding']->id), ['funder_organisation_id' => $region->id])))
        ->toBe(['funder' => [BillingPlans::HAS_INVOICES]]);
    expect($dossier->refresh()->only(['company_id', 'account_organisation_id']))->toBe(['company_id' => $x['dossier']->company_id, 'account_organisation_id' => $x['dossier']->account_organisation_id])
        ->and(Funding::query()->findOrFail($x['funding']->id)->funder_organisation_id)->not->toBe($region->id);

    // Anything else still changes; a funding never invoiced changes its funder.
    $dossiers->update($dossier, fvDossierPayload($dossier, ['notes' => 'Relancer en janvier']));
    $fundings->update($second->refresh(), ['funder_organisation_id' => $newcomer->id]);
    expect($dossier->refresh()->notes)->toBe('Relancer en janvier')
        ->and($second->refresh()->funder_organisation_id)->toBe($newcomer->id);
});

// ---------------------------------------------------------------- the absences rule, frozen

it('freezes the absences rule of a dossier at its first validated invoice', function () {
    $x = fiSeed();
    $user = fvAccountant();
    // Léa absent on the fourth séance: not billed (the organisme's default).
    fiLateAttendance($x, 3, 'absent');
    fvValidate(fvDraft($x, 'contractor', $user), $user);
    fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);
    expect(app(BillingPlans::class)->for($x['dossier']->id))->toMatchArray(['unjustified_absences' => 'not_billed', 'absences' => 'not_billed', 'locked' => true]);

    // The organisme now bills them: the dossier already invoiced keeps its rule, nothing new is due.
    FinanceSettings::saveAbsences('bill_contractor');
    $later = l2Dossier();
    expect(fiDue($x['dossier']->id))->toBe([])
        ->and(app(BillingPlans::class)->for($x['dossier']->id)['absences'])->toBe('not_billed')
        ->and(app(BillingPlans::class)->for($later->id)['absences'])->toBe('bill_contractor');

    // Choosing « the organisme's rule » on an invoiced dossier takes it as it is now, frozen again.
    app(BillingPlans::class)->save($x['dossier']->id, ['rhythm' => 'end', 'basis' => 'realised', 'unjustified_absences' => null]);
    FinanceSettings::saveAbsences('not_billed');
    expect(app(BillingPlans::class)->for($x['dossier']->id))->toMatchArray(['unjustified_absences' => 'bill_contractor', 'absences' => 'bill_contractor']);
});

it('refuses an invoice whose company is no longer the dossier\'s, until it is recalculated', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $draft = fvDraft($x, 'contractor', $user);
    $other = Company::factory()->create(['invoice_prefix' => 'FB', 'credit_note_prefix' => 'AVB']);
    // Nothing validated yet: the company of the dossier may change.
    $dossier = Dossier::query()->findOrFail($x['dossier']->id);
    app(DossierWriter::class)->update($dossier, fvDossierPayload($dossier, ['company_id' => $other->id]));

    expect(InvoiceValidator::COMPANY_CHANGED)->toBe('La société du dossier a changé : recalculez le brouillon avant de le valider.')
        ->and(fn () => fvValidate($draft->refresh(), $user))->toThrow(UserFacingHttpException::class, InvoiceValidator::COMPANY_CHANGED);
    expect($draft->refresh()->isDraft())->toBeTrue();

    $invoice = fvValidate(fiDrafts()->recompute($draft)->refresh(), $user);
    expect([$invoice->company_id, $invoice->number])->toBe([$other->id, 'FB-2026-00001']);
});

it('refuses to delete a funding already invoiced', function () {
    $x = fiSeed();
    $user = fvAccountant();
    $fundings = app(FundingWriter::class);
    $free = l2Funding($x['dossier'], ['amount' => '100,00']);
    fvValidate(fvDraft($x, 'funding:'.$x['funding']->id, $user), $user);

    try {
        $fundings->delete(Funding::query()->findOrFail($x['funding']->id));
        $this->fail('An invoiced funding should not be deleted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['funder' => [BillingPlans::HAS_INVOICES]]);
    }
    expect(Funding::query()->whereKey($x['funding']->id)->exists())->toBeTrue();

    // A funding never invoiced still goes.
    $fundings->delete($free);
    expect(Funding::query()->whereKey($free->id)->exists())->toBeFalse();
});
