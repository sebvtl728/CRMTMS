<?php

use App\Modules\Core\Tenancy\Rls;
use App\Modules\Core\Tenancy\RlsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs $fn with DDL executed on the owner connection inside a transaction
 * that is always rolled back, so probe tables never outlive the test.
 */
function withOwnerDdl(string $ddl, callable $fn): mixed
{
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();

    try {
        $owner->unprepared($ddl);

        return $fn();
    } finally {
        $owner->rollBack();
    }
}

it('every business table has tenant_id and an RLS policy', function () {
    $whitelist = config('tms.tenancy.unscoped_tables');
    $tables = collect(DB::connection('pgsql_owner')->select(
        "select tablename from pg_tables where schemaname = 'public'"))->pluck('tablename')
        ->reject(fn ($t) => in_array($t, $whitelist, true));

    expect($tables)->not->toBeEmpty(); // at least the probe_items fixture is audited

    foreach ($tables as $t) {
        expect(Schema::hasColumn($t, 'tenant_id'))->toBeTrue("$t sans tenant_id");
        expect(DB::connection('pgsql_owner')->selectOne(
            'select count(*) c from pg_policies where tablename = ?', [$t])->c)->toBeGreaterThan(0, "$t sans RLS");
    }

    // Stricter audit: uuid not null, RLS enabled + forced, tenant_isolation policy, no widening policy.
    expect(app(RlsAudit::class)->violations())->toBe([]);
});

it('only whitelists tables that exist', function () {
    $existing = collect(DB::connection('pgsql_owner')->select(
        "select tablename from pg_tables where schemaname = 'public'"))->pluck('tablename')->all();

    expect(array_diff(config('tms.tenancy.unscoped_tables'), $existing))->toBe([]);
});

it('flags a business table without tenant_id', function () {
    withOwnerDdl('create table arch_probe_no_tenant (id uuid primary key, label text)', function () {
        expect(app(RlsAudit::class)->violations('pgsql_owner'))
            ->toHaveKey('arch_probe_no_tenant')
            ->and(app(RlsAudit::class)->violations('pgsql_owner')['arch_probe_no_tenant'])
            ->toContain('colonne tenant_id absente');
    });
});

it('flags a business table with tenant_id but no row level security', function () {
    withOwnerDdl('create table arch_probe_no_rls (id uuid primary key, tenant_id uuid not null)', function () {
        expect(app(RlsAudit::class)->violations('pgsql_owner')['arch_probe_no_rls'] ?? [])
            ->toContain('RLS non activée')
            ->toContain('RLS non forcée')
            ->toContain('politique tenant_isolation absente');
    });
});

it('flags a nullable tenant_id', function () {
    withOwnerDdl('create table arch_probe_nullable (id uuid primary key, tenant_id uuid)', function () {
        expect(app(RlsAudit::class)->violations('pgsql_owner')['arch_probe_nullable'] ?? [])
            ->toContain('tenant_id doit être uuid not null');
    });
});

it('flags an extra permissive policy that would widen access', function () {
    withOwnerDdl(<<<'SQL'
        create table arch_probe_leaky (id uuid primary key, tenant_id uuid not null);
        alter table arch_probe_leaky enable row level security;
        alter table arch_probe_leaky force row level security;
        create policy tenant_isolation on arch_probe_leaky
            using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
        create policy open_bar on arch_probe_leaky using (true);
        SQL, function () {
        expect(app(RlsAudit::class)->violations('pgsql_owner')['arch_probe_leaky'] ?? [])
            ->toContain('politique permissive supplémentaire : open_bar');
    });
});

it('accepts a table protected with Rls::enable', function () {
    withOwnerDdl('create table arch_probe_ok (id uuid primary key, tenant_id uuid not null)', function () {
        Rls::enable('arch_probe_ok', 'pgsql_owner');

        expect(app(RlsAudit::class)->violations('pgsql_owner'))->not->toHaveKey('arch_probe_ok');
    });
});
