<?php

namespace App\Modules\Core\Tenancy;

use App\Modules\Core\Security\DatabaseErrorLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a piece of work once per organisme, in its tenant context (scheduled
 * tasks, permission sync, purges...).
 *
 * One tenant failing never stops the others: the exception is logged with the
 * tenant id and the loop continues. The tenants that failed are returned so
 * the caller can report them (exit code, alert). The context active before the
 * call (usually none) is restored afterwards, whatever happens.
 */
final class ForEachTenant
{
    /**
     * @param  callable(Tenant): mixed  $fn
     * @return list<Tenant> the tenants whose work failed
     */
    public static function run(callable $fn, bool $includeSuspended = false): array
    {
        $failed = [];
        $context = app(TenantContext::class);

        $tenants = Tenant::query()
            ->when(! $includeSuspended, fn ($q) => $q->where('status', TenantStatus::Active))
            ->orderBy('slug')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $context->run($tenant, fn () => $fn($tenant));
            } catch (Throwable $e) {
                $failed[] = $tenant;
                Log::error('Échec du traitement pour un organisme', [
                    'tenant_id' => $tenant->id,
                    'tenant' => $tenant->slug,
                    ...DatabaseErrorLog::context($e),
                ]);
            }
        }

        return $failed;
    }
}
