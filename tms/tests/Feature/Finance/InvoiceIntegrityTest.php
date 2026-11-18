<?php

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceJournalEntry;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

it('detects a deleted tail: a number given that is no longer in the journal', function () {
    fgAsOwner(function (Connection $owner) {
        plSetUp(Tenant::factory()->create(['slug' => 'integrity-'.Str::lower(Str::random(8))]));
        [, $second] = fgTwoInvoices();

        foreach (['invoice_journal', 'invoices', 'invoice_lines'] as $table) {
            $owner->statement("alter table {$table} disable trigger {$table}_immutable");
        }
        $owner->delete('delete from invoice_journal where invoice_id = ?', [$second->id]);
        $owner->delete('delete from invoice_lines where invoice_id = ?', [$second->id]);
        $owner->delete('delete from invoices where id = ?', [$second->id]);
        foreach (['invoice_journal', 'invoices', 'invoice_lines'] as $table) {
            $owner->statement("alter table {$table} enable trigger {$table}_immutable");
        }

        // The chain of what is left links, but F-2026-00002 was given.
        expect(Integrity::verify())->toBe(['count' => 1, 'broken' => 'F-2026-00002']);
    });
});

it('detects an archived PDF replaced on the disk', function () {
    plSetUp();
    [$first] = fgTwoInvoices();
    expect($first->refresh()->pdf_sha256)->toBe(hash('sha256', '%PDF-1.7 facture'));

    Storage::disk(TenantStorage::DISK)->put((string) $first->pdf_path, '%PDF-1.7 une autre facture');
    expect(Integrity::verify())->toBe(['count' => 1, 'broken' => 'F-2026-00001']);

    Storage::disk(TenantStorage::DISK)->delete((string) $first->pdf_path);
    expect(Integrity::verify()['broken'])->toBe('F-2026-00001');
});

/**
 * Runs $fn outside the per-test transaction (rolled back first, opened again
 * afterwards), so other connections see what the fixtures commit.
 */
function fgCommitted(Closure $fn): void
{
    $app = DB::connection();
    $level = $app->transactionLevel();
    while ($app->transactionLevel() > 0) {
        $app->rollBack();
    }
    app(TenantContext::class)->clear();
    try {
        $fn();
    } finally {
        DB::setDefaultConnection('pgsql');
        config(['tms.tenancy.connection' => 'pgsql']);
        app(TenantContext::class)->clear();
        for ($i = 0; $i < $level; $i++) {
            $app->beginTransaction();
        }
    }
}

/** A runtime connection (tms_app) of its own, the default one and the tenancy one, acting for $tenant. */
function fgActAs(string $name, Tenant $tenant): Connection
{
    if (config("database.connections.{$name}") === null) {
        config(["database.connections.{$name}" => config('database.connections.pgsql')]);
    }
    DB::setDefaultConnection($name);
    config(['tms.tenancy.connection' => $name]);
    app(TenantContext::class)->set($tenant);

    return DB::connection($name);
}

it('keeps the journal linear under two concurrent validations in one organisme (two companies)', function () {
    fgCommitted(function () {
        $tenantId = (string) Str::uuid7();
        $ids = ['das' => (string) Str::uuid7(), 'org' => (string) Str::uuid7(), 'user' => (string) Str::uuid7()];
        $setup = fgActAs('fg_setup', (new Tenant)->newFromBuilder(['id' => '00000000-0000-0000-0000-000000000000']));
        $setup->table('tenants')->insert(['id' => $tenantId, 'name' => 'Concurrence', 'slug' => 'concurrency-'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()]);
        try {
            $tenant = Tenant::on('fg_setup')->findOrFail($tenantId);
            fgActAs('fg_setup', $tenant);
            $now = now();
            $setup->table('reference_items')->insert(['id' => $ids['das'], 'tenant_id' => $tenantId, 'list' => 'das', 'code' => 'x', 'label' => 'DAS', 'created_at' => $now, 'updated_at' => $now]);
            $setup->table('organisations')->insert(['id' => $ids['org'], 'tenant_id' => $tenantId, 'name' => 'Client', 'created_at' => $now, 'updated_at' => $now]);
            $setup->table('users')->insert(['id' => $ids['user'], 'tenant_id' => $tenantId, 'name' => 'Comptable', 'email' => 'compta@example.test', 'created_at' => $now, 'updated_at' => $now]);
            $drafts = [];
            foreach (['A', 'B'] as $i => $letter) {
                $company = (string) Str::uuid7();
                $dossier = (string) Str::uuid7();
                $invoice = (string) Str::uuid7();
                $setup->table('companies')->insert(['id' => $company, 'tenant_id' => $tenantId, 'name' => "Société {$letter}", 'quote_prefix' => "Q{$letter}", 'vat_regime' => 'exempt', 'created_at' => $now, 'updated_at' => $now]);
                $setup->table('dossiers')->insert(['id' => $dossier, 'tenant_id' => $tenantId, 'number' => "D-2026-000{$i}", 'company_id' => $company, 'account_organisation_id' => $ids['org'], 'das_id' => $ids['das'], 'created_at' => $now, 'updated_at' => $now]);
                $setup->table('invoices')->insert(['id' => $invoice, 'tenant_id' => $tenantId, 'company_id' => $company, 'kind' => 'invoice', 'dossier_id' => $dossier, 'recipient_type' => 'organisation', 'recipient_id' => $ids['org'], 'rhythm' => 'end', 'basis' => 'realised', 'total_ht_cents' => 10000, 'total_ttc_cents' => 10000, 'created_at' => $now, 'updated_at' => $now]);
                $setup->table('invoice_lines')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'invoice_id' => $invoice, 'position' => 0, 'kind' => 'free', 'label' => 'Frais', 'quantity' => 1, 'unit' => 'forfait', 'unit_price_cents' => 10000, 'vat_rate' => 0, 'total_ht_cents' => 10000, 'created_at' => $now, 'updated_at' => $now]);
                $drafts[$letter] = $invoice;
            }
            $user = User::on('fg_setup')->findOrFail($ids['user']);

            // A validates, its transaction still open (journal of the organisme locked).
            $a = fgActAs('fg_a', $tenant);
            $a->beginTransaction();
            $first = app(InvoiceValidator::class)->validate(Invoice::query()->findOrFail($drafts['A']), $user);
            expect($first->number)->toBe('F-2026-00001');

            // B (another company: another sequence) waits for the journal: with a short timeout it gives up.
            $b = fgActAs('fg_b', $tenant);
            $b->beginTransaction();
            $b->statement("set local lock_timeout = '300ms'");
            $blocked = null;
            try {
                app(InvoiceValidator::class)->validate(Invoice::query()->findOrFail($drafts['B']), $user);
            } catch (QueryException $e) {
                $blocked = $e;
            }
            expect($blocked?->getCode())->toBe('55P03');
            $b->rollBack();

            $a->commit();
            fgActAs('fg_b', $tenant);
            $second = app(InvoiceValidator::class)->validate(Invoice::query()->findOrFail($drafts['B']), $user);

            expect($second->number)->toBe('F-2026-00001')
                ->and(InvoiceJournalEntry::query()->orderBy('position')->get()->map->only(['position', 'previous_hash'])->all())->toBe([
                    ['position' => 1, 'previous_hash' => null],
                    ['position' => 2, 'previous_hash' => $first->hash],
                ])
                ->and(Integrity::verify())->toBe(['count' => 2, 'broken' => null]);
        } finally {
            foreach (['fg_a', 'fg_b'] as $name) {
                while (DB::connection($name)->transactionLevel() > 0) {
                    DB::connection($name)->rollBack();
                }
            }
            // Validated invoices and the audit trail are frozen: the owner lifts the triggers to clean up.
            $owner = DB::connection('pgsql_owner');
            $owner->transaction(function () use ($owner, $tenantId) {
                $owner->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
                foreach (['invoice_journal', 'invoices', 'invoice_lines', 'audit_logs'] as $table) {
                    $owner->statement("alter table {$table} disable trigger {$table}_immutable");
                }
                $owner->delete('delete from invoice_journal where tenant_id = ?', [$tenantId]);
                $owner->delete('delete from invoices where tenant_id = ?', [$tenantId]);
                $owner->delete('delete from audit_logs where tenant_id = ?', [$tenantId]);
                foreach (['invoice_journal', 'invoices', 'invoice_lines', 'audit_logs'] as $table) {
                    $owner->statement("alter table {$table} enable trigger {$table}_immutable");
                }
                $owner->delete('delete from tenants where id = ?', [$tenantId]);
            });
            foreach (['fg_setup', 'fg_a', 'fg_b'] as $name) {
                DB::purge($name);
            }
        }
    });
});
