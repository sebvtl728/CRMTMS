<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(QueueTenancy::class);
    }

    public function boot(): void
    {
        $this->bootQueue();

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

    private function bootQueue(): void
    {
        // The tenant travels in the payload, so the worker can establish it
        // BEFORE the job is unserialized (SerializesModels hydration runs
        // under RLS, ahead of any job middleware).
        // The hook list is static (process-wide; the test lifecycle flushes it
        // between tests): resolve through the current container, never a
        // captured one, so a duplicate registration stays harmless.
        Queue::createPayloadUsing(fn (?string $connection, ?string $queue, array $payload): array => app(QueueTenancy::class)->payloadFor($payload));

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->app->make(QueueTenancy::class)->starting($event->job));
        // Fired once per attempt, after success or failure handling (failed() included).
        Event::listen(JobAttempted::class, fn (JobAttempted $event) => $this->app->make(QueueTenancy::class)->finished($event->job));
        // Between two jobs of a long-lived worker: never carry a tenant over.
        Event::listen(Looping::class, fn () => $this->app->make(TenantContext::class)->clear());
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
