<?php

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceJournalEntry;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';

beforeEach(function () {
    fakeImportDisk();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 facture', 200, ['Content-Type' => 'application/pdf'])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * The contractor's and the OPCO's invoices of a seeded dossier, validated:
 * F-2026-00001 and F-2026-00002.
 *
 * @return list<Invoice>
 */
function fgTwoInvoices(): array
{
    $x = fiSeed();
    $user = fiManager([Permissions::VALIDATE => null]);
    $validated = [];
    foreach (['contractor', 'funding:'.$x['funding']->id] as $recipient) {
        fiDrafts()->prepare($user, [$x['dossier']->id.'|'.$recipient]);
        $draft = Invoice::query()->where('dossier_id', $x['dossier']->id)->where('status', InvoiceStatus::Draft)->sole();
        $validated[] = app(InvoiceValidator::class)->validate($draft, $user);
    }

    return $validated;
}

it('finds the chain whole after validations', function () {
    plSetUp();
    [$first, $second] = fgTwoInvoices();

    expect(Integrity::verify())->toBe(['count' => 2, 'broken' => null])
        ->and($second->refresh()->previous_hash)->toBe($first->refresh()->hash);

    $this->artisan('tms:finance:verify')
        ->expectsOutputToContain('intégrité vérifiée (2 pièces)')
        ->assertExitCode(0);
});

it('detects a journal row forged by the application role', function () {
    plSetUp();
    fgTwoInvoices();
    // The journal accepts inserts only: a forged row breaks the chain at its number.
    $draft = fiDrafts()->manual(User::query()->firstOrFail(), Invoice::query()->firstOrFail()->dossier_id, 'contractor');
    InvoiceJournalEntry::query()->create([
        'invoice_id' => $draft->id, 'position' => 3, 'number' => 'F-2026-00099', 'kind' => 'invoice', 'issued_on' => '2026-11-17',
        'total_ht_cents' => 100, 'total_vat_cents' => 0, 'total_ttc_cents' => 100,
        'hash' => str_repeat('a', 64), 'previous_hash' => InvoiceJournalEntry::query()->where('position', 2)->value('hash'),
    ]);

    expect(Integrity::verify())->toBe(['count' => 3, 'broken' => 'F-2026-00099']);
    $this->artisan('tms:finance:verify')
        ->expectsOutputToContain('intégrité rompue à la pièce F-2026-00099')
        ->assertExitCode(1);
});

/**
 * Runs $fn as the owner of the schema (who may disable a trigger), on its
 * own connection in a transaction rolled back afterwards: the whole
 * scenario lives there. The per-test transaction of the application role
 * is rolled back first (what it wrote would be invisible to the owner, and
 * its uncommitted global rows — permissions — would block the owner's),
 * then opened again for the end of the test.
 */
function fgAsOwner(Closure $fn): void
{
    $previous = DB::getDefaultConnection();
    $app = DB::connection($previous);
    $level = $app->transactionLevel();
    while ($app->transactionLevel() > 0) {
        $app->rollBack();
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Scopes::forget();
    app(TenantContext::class)->clear();

    /** @var Connection $owner */
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();
    DB::setDefaultConnection('pgsql_owner');
    $tenancy = config('tms.tenancy.connection');
    config(['tms.tenancy.connection' => 'pgsql_owner']);
    try {
        $fn($owner);
    } finally {
        config(['tms.tenancy.connection' => $tenancy]);
        DB::setDefaultConnection($previous);
        $owner->rollBack();
        app(TenantContext::class)->clear();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        for ($i = 0; $i < $level; $i++) {
            $app->beginTransaction();
        }
    }
}

it('detects an invoice changed behind the triggers (as the owner of the schema), naming the first broken number', function () {
    fgAsOwner(function (Connection $owner) {
        plSetUp(Tenant::factory()->create(['slug' => 'integrity-'.Str::lower(Str::random(8))]));
        [, $second] = fgTwoInvoices();
        expect(Integrity::verify()['broken'])->toBeNull();

        // The amount of an invoice.
        $owner->statement('alter table invoices disable trigger invoices_immutable');
        $owner->update('update invoices set total_ht_cents = 100, total_ttc_cents = 100 where id = ?', [$second->id]);
        expect(Integrity::verify())->toBe(['count' => 2, 'broken' => 'F-2026-00002']);
        $owner->update('update invoices set total_ht_cents = ?, total_ttc_cents = ? where id = ?', [$second->total_ht_cents, $second->total_ttc_cents, $second->id]);
        $owner->statement('alter table invoices enable trigger invoices_immutable');
        expect(Integrity::verify()['broken'])->toBeNull();

        // The label of a line: the totals still match, the hash does not.
        $owner->statement('alter table invoice_lines disable trigger invoice_lines_immutable');
        $owner->update("update invoice_lines set label = 'Autre formation' where invoice_id = ?", [$second->id]);
        $owner->statement('alter table invoice_lines enable trigger invoice_lines_immutable');
        expect(Integrity::verify())->toBe(['count' => 2, 'broken' => 'F-2026-00002']);
        $this->artisan('tms:finance:verify')->expectsOutputToContain('intégrité rompue à la pièce F-2026-00002')->assertExitCode(1);
    });
});
