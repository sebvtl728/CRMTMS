<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a piece of work once per organisme, in its tenant context (scheduled
 * tasks, permission sync, purges...).
 *
 * One tenant failing never stops the others: the exception is logged with the
 * tenant id and the loop continues. The context active before the call
 * (usually none) is restored afterwards, whatever happens.
 */
final class ForEachTenant
{
    /**
     * @param  callable(Tenant): mixed  $fn
     */
    public static function run(callable $fn, bool $includeSuspended = false): void
    {
        $context = app(TenantContext::class);

        $tenants = Tenant::query()
            ->when(! $includeSuspended, fn ($q) => $q->where('status', TenantStatus::Active))
            ->orderBy('slug')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $context->run($tenant, fn () => $fn($tenant));
            } catch (Throwable $e) {
                Log::error('Échec du traitement pour un organisme', [
                    'tenant_id' => $tenant->id,
                    'tenant' => $tenant->slug,
                    'exception' => $e,
                ]);
            }
        }
    }
}
