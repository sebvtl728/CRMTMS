<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Support\Facades\Log;

/**
 * Puts the current tenant id into the context shared by every log channel
 * (Log::shareContext), so each log line of a request, job or command says
 * which organisme it was written for. Kept in sync by TenantContext: set when
 * a tenant becomes current (ResolveTenant, the queue's JobProcessing listener,
 * TenantContext::run), removed when the context is cleared.
 */
final class TenantLogContext
{
    public const KEY = 'tenant_id';

    public static function apply(?string $tenantId): void
    {
        if ($tenantId !== null) {
            Log::shareContext([self::KEY => $tenantId]);

            return;
        }

        // shareContext() merges: rebuild the shared context without the key
        // (for channels created later) and strip it from the resolved ones.
        $shared = Log::sharedContext();
        if (! array_key_exists(self::KEY, $shared)) {
            return;
        }

        unset($shared[self::KEY]);
        Log::flushSharedContext();
        Log::withoutContext([self::KEY]);
        Log::shareContext($shared);
    }
}
