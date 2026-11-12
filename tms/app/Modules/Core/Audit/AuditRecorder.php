<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Single writer of the audit journal. Rows are written in the caller's
 * transaction (a rolled-back change leaves no row). Without a tenant context
 * nothing can be written (RLS): the event is skipped with a warning rather
 * than breaking the request.
 */
class AuditRecorder
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  User|null  $actor  defaults to the authenticated user
     */
    public function record(string $event, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null): void
    {
        if ($this->tenants->current() === null) {
            Log::warning('Audit event skipped: no tenant context.', ['event' => $event]);

            return;
        }

        $actor ??= Auth::user() instanceof User ? Auth::user() : null;
        $request = app()->bound('request') && (! app()->runningInConsole() || app()->runningUnitTests()) ? app(Request::class) : null;

        AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    /**
     * Seam for exports (none exist yet): call it when an export is generated.
     *
     * @param  array<string, mixed>  $parameters  filters/format; no personal data
     */
    public function export(string $export, array $parameters = []): void
    {
        $this->record('export.run', null, [], ['export' => $export, ...$parameters]);
    }
}
