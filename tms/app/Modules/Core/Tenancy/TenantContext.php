<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The tenant (organisme) the current request / job / command acts for.
 *
 * Registered as a singleton. Every change is mirrored into the PostgreSQL
 * session setting app.tenant_id on the runtime connection, which is what the
 * RLS policies (Rls::enable) compare tenant_id against. No tenant means the
 * setting is '' and every RLS-protected table reads as empty. It is also
 * mirrored into the shared log context (TenantLogContext).
 *
 * The setting is session-level (set_config(..., false)) so it survives the
 * transactions of a request; see TenancyServiceProvider for how it is kept in
 * sync on reconnects and transaction rollbacks.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function set(Tenant $tenant): void
    {
        // Database first: if it fails, PHP must not believe a tenant is active.
        $this->writeSetting($tenant->id);
        $this->tenant = $tenant;
        TenantLogContext::apply($tenant->id);
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * @throws MissingTenantContext
     */
    public function id(): string
    {
        return $this->tenant->id ?? throw new MissingTenantContext;
    }

    public function clear(): void
    {
        // PHP first: fail closed even if the database call fails.
        $this->tenant = null;
        TenantLogContext::apply(null);
        $this->writeSetting('');
    }

    /**
     * Runs $fn as $tenant, then restores whatever context was active before
     * (including "none"), even if $fn throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $fn
     * @return TReturn
     */
    public function run(Tenant $tenant, callable $fn): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $fn();
        } finally {
            // PHP state first: if the write fails (e.g. $fn left the transaction
            // aborted), the rollback listener re-syncs the database from it.
            $this->tenant = $previous;
            TenantLogContext::apply($previous?->id);
            $this->syncDatabaseSetting();
        }
    }

    /**
     * Re-writes the database setting from the PHP state (after a reconnect or
     * a rollback that may have reverted it).
     */
    public function syncDatabaseSetting(): void
    {
        $this->writeSetting($this->tenant->id ?? '');
    }

    public function connectionName(): string
    {
        return (string) config('tms.tenancy.connection', 'pgsql');
    }

    private function writeSetting(string $tenantId): void
    {
        // statement() runs on the write PDO, the one used by this connection.
        $this->connection()->statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);
    }

    private function connection(): Connection
    {
        return $this->db->connection($this->connectionName());
    }
}
