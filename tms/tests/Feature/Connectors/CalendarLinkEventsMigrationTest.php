<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Replays the 0.8.1 generalisation of calendar_link_events on the owner
 * connection, inside a transaction always rolled back.
 */
function linkEventsMigration(): object
{
    return require database_path('migrations/2027_01_05_000002_generalise_calendar_link_events.php');
}

/**
 * Runs $fn with the owner connection as default, in a transaction rolled
 * back afterwards (the schema and the rows written never outlive the test).
 */
function asOwnerRolledBack(callable $fn): void
{
    $previous = DB::getDefaultConnection();
    /** @var Connection $owner */
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();
    DB::setDefaultConnection('pgsql_owner');
    try {
        $fn($owner);
    } finally {
        DB::setDefaultConnection($previous);
        $owner->rollBack();
    }
}

it('turns activity_id into a source and a source_id, the actions as crm.activity, and back', function () {
    asOwnerRolledBack(function (Connection $owner): void {
        // An organisme of its own: the test's rows (other connection) are not visible here.
        $tenant = (object) ['id' => (string) Str::uuid7()];
        $owner->table('tenants')->insert(['id' => $tenant->id, 'name' => 'Organisme de migration', 'slug' => 'migration-'.Str::lower(Str::random(8))]);
        $owner->statement("select set_config('app.tenant_id', ?, true)", [$tenant->id]);
        $userId = (string) Str::uuid7();
        $linkId = (string) Str::uuid7();
        $owner->table('users')->insert(['id' => $userId, 'tenant_id' => $tenant->id, 'name' => 'Paul Martin', 'email' => 'migration-'.Str::random(8).'@example.test']);
        $owner->table('calendar_links')->insert(['id' => $linkId, 'tenant_id' => $tenant->id, 'user_id' => $userId, 'status' => 'active']);
        $shared = (string) Str::uuid7();
        $row = fn (string $source, ?string $id = null): array => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'calendar_link_id' => $linkId,
            'source' => $source, 'source_id' => $id ?? (string) Str::uuid7(), 'transaction_id' => (string) Str::uuid7(),
        ];

        // Migrated: the same id may have a copy per source, never two for one source.
        expect(Schema::hasColumns('calendar_link_events', ['source', 'source_id']))->toBeTrue()
            ->and(Schema::hasColumn('calendar_link_events', 'activity_id'))->toBeFalse();
        $owner->table('calendar_link_events')->insert([$row('crm.activity', $shared), $row('sessions.seance', $shared)]);
        $owner->statement('savepoint duplicate');
        expect(fn () => $owner->table('calendar_link_events')->insert($row('sessions.seance', $shared)))->toThrow(UniqueConstraintViolationException::class);
        $owner->statement('rollback to savepoint duplicate');
        // No default: a copy always names its source.
        $owner->statement('savepoint unnamed');
        expect(fn () => $owner->table('calendar_link_events')->insert(array_diff_key($row('crm.activity'), ['source' => true])))
            ->toThrow(QueryException::class, 'not-null');
        $owner->statement('rollback to savepoint unnamed');

        linkEventsMigration()->down();

        expect(Schema::hasColumn('calendar_link_events', 'source'))->toBeFalse()
            ->and($owner->table('calendar_link_events')->pluck('activity_id')->all())->toBe([$shared]);

        linkEventsMigration()->up();

        $kept = $owner->table('calendar_link_events')->sole();
        expect($kept->source)->toBe('crm.activity')->and($kept->source_id)->toBe($shared);
    });
});
