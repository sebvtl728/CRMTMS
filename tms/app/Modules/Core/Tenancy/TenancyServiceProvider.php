<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // app.tenant_id is a session-level setting: a new session (reconnect
        // after a lost connection) starts without it. Re-apply the context so
        // the PHP state and the database never disagree.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $context = $this->resolvedContext();

            if ($context !== null && $context->current() !== null && $event->connectionName === $context->connectionName()) {
                $context->syncDatabaseSetting();
            }
        });

        // set_config() is transactional: a rollback (or rollback to savepoint)
        // reverts it to the value before the transaction, possibly another
        // tenant. Re-apply the PHP state after every rollback.
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $context = $this->resolvedContext();

            if ($context !== null && $event->connectionName === $context->connectionName()) {
                $context->syncDatabaseSetting();
            }
        });
    }

    /**
     * Never instantiate the context from a listener: if nobody resolved it,
     * no tenant was ever set in this process.
     */
    private function resolvedContext(): ?TenantContext
    {
        return $this->app->resolved(TenantContext::class) ? $this->app->make(TenantContext::class) : null;
    }
}
