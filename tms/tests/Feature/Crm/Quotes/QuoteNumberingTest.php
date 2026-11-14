<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Quotes\QuoteNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A runtime connection (tms_app) of its own, outside the per-test
 * transaction of RefreshDatabase, acting for $tenantId.
 */
function numberingConnection(string $name, string $tenantId): Connection
{
    config(["database.connections.{$name}" => config('database.connections.pgsql')]);
    DB::purge($name);
    $connection = DB::connection($name);
    $connection->statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);

    return $connection;
}

/**
 * A company loaded on $connection (so QuoteNumbering works on that session).
 */
function companyOn(string $connection, string $id, string $tenantId, string $prefix = 'DEV'): Company
{
    return (new Company)->newFromBuilder(['id' => $id, 'tenant_id' => $tenantId, 'quote_prefix' => $prefix], $connection);
}

it('numbering is gapless under concurrency and resets per paris year', function () {
    // Committed fixtures (the per-test transaction is invisible to other
    // sessions): a tenant and a company, removed in finally.
    $setup = numberingConnection('numbering_setup', '');
    $tenantId = (string) Str::uuid7();
    $companyId = (string) Str::uuid7();
    $setup->table('tenants')->insert(['id' => $tenantId, 'name' => 'Numérotation', 'slug' => 'numbering-'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()]);
    $setup->statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);
    $setup->table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenantId, 'name' => 'Société numérotée', 'created_at' => now(), 'updated_at' => now()]);

    $a = numberingConnection('numbering_a', $tenantId);
    $b = numberingConnection('numbering_b', $tenantId);
    $numbering = new QuoteNumbering;
    $at = CarbonImmutable::parse('2026-06-15 10:00', 'Europe/Paris');

    try {
        $a->beginTransaction();
        $b->beginTransaction();

        expect($numbering->next(companyOn('numbering_a', $companyId, $tenantId), $at))->toBe('DEV-2026-0001');

        // B must wait for A's row lock: with a short lock timeout it gives up
        // instead of reading the same number.
        $b->statement("set local lock_timeout = '300ms'");
        $blocked = null;
        try {
            $numbering->next(companyOn('numbering_b', $companyId, $tenantId), $at);
        } catch (QueryException $e) {
            $blocked = $e;
        }
        expect($blocked)->not->toBeNull()
            ->and($blocked?->getCode())->toBe('55P03'); // lock_not_available
        $b->rollBack();

        // A commits; B (new transaction) now gets the next number, no gap, no duplicate.
        $a->commit();
        $b->beginTransaction();
        expect($numbering->next(companyOn('numbering_b', $companyId, $tenantId), $at))->toBe('DEV-2026-0002');
        $b->commit();

        // A rolled back number is not consumed (the row update is undone).
        $a->beginTransaction();
        expect($numbering->next(companyOn('numbering_a', $companyId, $tenantId), $at))->toBe('DEV-2026-0003');
        $a->rollBack();
        $a->beginTransaction();
        expect($numbering->next(companyOn('numbering_a', $companyId, $tenantId), $at))->toBe('DEV-2026-0003');

        // Paris calendar year: 31/12 23:59 Paris is still 2026, 01/01 00:00 Paris
        // (31/12 23:00 UTC) starts 2027 at 0001.
        expect($numbering->next(companyOn('numbering_a', $companyId, $tenantId), CarbonImmutable::parse('2026-12-31 23:59', 'Europe/Paris')))->toBe('DEV-2026-0004')
            ->and($numbering->next(companyOn('numbering_a', $companyId, $tenantId), CarbonImmutable::parse('2026-12-31 23:00', 'UTC')))->toBe('DEV-2027-0001')
            ->and($numbering->next(companyOn('numbering_a', $companyId, $tenantId), CarbonImmutable::parse('2027-01-01 00:00', 'Europe/Paris')))->toBe('DEV-2027-0002');
        $a->commit();

        expect($setup->table('quote_sequences')->where('tenant_id', $tenantId)->where('prefix', 'DEV')->orderBy('year')->pluck('last_number', 'year')->all())
            ->toBe([2026 => 4, 2027 => 2]);
    } finally {
        foreach ([$a, $b] as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $owner = DB::connection('pgsql_owner');
        $owner->table('tenants')->where('id', $tenantId)->delete(); // cascades companies and quote_sequences
        foreach (['numbering_setup', 'numbering_a', 'numbering_b'] as $name) {
            DB::purge($name);
        }
    }
});

it('refuses to number outside a transaction', function () {
    $tenant = inTenant();
    numberingConnection('numbering_a', $tenant->id);

    try {
        (new QuoteNumbering)->next(companyOn('numbering_a', (string) Str::uuid7(), $tenant->id), CarbonImmutable::now());
    } finally {
        DB::purge('numbering_a');
    }
})->throws(LogicException::class);

it('takes the year in paris time and pads the number', function () {
    inTenant();
    $company = Company::factory()->create();
    $numbering = new QuoteNumbering;

    expect($numbering->next($company, CarbonImmutable::parse('2026-12-31 22:59', 'UTC')))->toBe('DEV-2026-0001') // 23:59 Paris
        ->and($numbering->next($company, CarbonImmutable::parse('2026-12-31 23:00', 'UTC')))->toBe('DEV-2027-0001') // 00:00 Paris
        ->and($numbering->next($company, CarbonImmutable::parse('2027-03-01 12:00', 'Europe/Paris')))->toBe('DEV-2027-0002');
});

it('numbers each prefix with its own sequence', function () {
    inTenant();
    $cap = Company::factory()->create(['quote_prefix' => 'CAP']);
    $other = Company::factory()->create(['quote_prefix' => 'F2']);
    $at = CarbonImmutable::parse('2026-05-01', 'Europe/Paris');
    $numbering = new QuoteNumbering;

    expect($numbering->next($cap, $at))->toBe('CAP-2026-0001')
        ->and($numbering->next($cap, $at))->toBe('CAP-2026-0002')
        ->and($numbering->next($other, $at))->toBe('F2-2026-0001');

    // The sequence belongs to the prefix: a new prefix starts its own, and a
    // prefix given up then taken by another company carries on (no duplicate).
    $cap->update(['quote_prefix' => 'CF']);
    $other->update(['quote_prefix' => 'CAP']);
    expect($numbering->next($cap->fresh(), $at))->toBe('CF-2026-0001')
        ->and($numbering->next($other->fresh(), $at))->toBe('CAP-2026-0003')
        ->and(DB::table('quote_sequences')->orderBy('prefix')->pluck('last_number', 'prefix')->all())->toBe(['CAP' => 3, 'CF' => 1, 'F2' => 1]);
});

it('keeps the numbering of another organisme independent', function () {
    $first = inTenant();
    $mine = Company::factory()->create();
    $numbering = new QuoteNumbering;
    $at = CarbonImmutable::parse('2026-05-01', 'Europe/Paris');
    expect($numbering->next($mine, $at))->toBe('DEV-2026-0001')
        ->and($numbering->next($mine, $at))->toBe('DEV-2026-0002');

    $other = Tenant::factory()->create();
    app(TenantContext::class)->set($other);
    $theirs = Company::factory()->create();

    expect($numbering->next($theirs, $at))->toBe('DEV-2026-0001')
        ->and(DB::table('quote_sequences')->pluck('tenant_id')->all())->toBe([$other->id]); // RLS: only its own rows

    // A company of another organisme cannot be numbered from here (RLS check).
    expect(fn () => DB::transaction(fn () => $numbering->next($mine, $at)))->toThrow(QueryException::class);

    app(TenantContext::class)->set($first);
    expect($numbering->next($mine, $at))->toBe('DEV-2026-0003');
});
