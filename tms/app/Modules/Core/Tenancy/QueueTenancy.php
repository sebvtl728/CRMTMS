<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Contracts\Queue\Job;

/**
 * Carries the tenant across the queue.
 *
 * - On push: adds "tenantId" to the payload (the job's captured tenant for a
 *   TenantAwareJob, otherwise the context active at dispatch, if any).
 * - On JobProcessing: remembers the worker's context, then sets the payload
 *   tenant (or none) before the command is unserialized.
 * - On JobAttempted: restores the remembered context. On a worker that is
 *   "none"; for a sync job it is the caller's context.
 */
class QueueTenancy
{
    public const PAYLOAD_KEY = 'tenantId';

    /** @var array<int, Tenant|null> previous context, keyed by job object */
    private array $previous = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payloadFor(array $payload): array
    {
        $command = $payload['data']['command'] ?? null;

        $tenantId = is_object($command) && property_exists($command, 'tenantId') && isset($command->tenantId) && is_string($command->tenantId)
            ? $command->tenantId
            : $this->context->current()?->id;

        return $tenantId === null ? [] : [self::PAYLOAD_KEY => $tenantId];
    }

    public function starting(Job $job): void
    {
        $this->previous[spl_object_id($job)] = $this->context->current();

        $tenantId = $job->payload()[self::PAYLOAD_KEY] ?? null;
        $tenant = is_string($tenantId) ? Tenant::query()->find($tenantId) : null;

        // Unknown tenant: run without context (fails closed under RLS).
        $tenant === null ? $this->context->clear() : $this->context->set($tenant);
    }

    public function finished(Job $job): void
    {
        $key = spl_object_id($job);

        if (! array_key_exists($key, $this->previous)) {
            return;
        }

        $previous = $this->previous[$key];
        unset($this->previous[$key]);

        $previous === null ? $this->context->clear() : $this->context->set($previous);
    }
}
