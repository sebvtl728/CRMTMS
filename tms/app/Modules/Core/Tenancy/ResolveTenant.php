<?php

namespace App\Modules\Core\Tenancy;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * First middleware of the "web" group (before session and authentication):
 * resolves the tenant of the request and sets the TenantContext.
 * Not applied to /health (routes/health.php is outside the web group).
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

        if ($tenant === null) {
            return $this->unavailable(404, 'Organisme introuvable', 'Aucun organisme n\'est associé à cette adresse.');
        }

        if ($tenant->isSuspended()) {
            return $this->unavailable(503, 'Organisme suspendu', 'L\'accès à cet organisme est suspendu. Contactez votre administrateur.');
        }

        $this->context->set($tenant);

        return $next($request);
    }

    /**
     * Long-lived workers (Octane, queue) reuse the same process and database
     * session: never let a tenant leak into the next request.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }

    private function resolve(Request $request): ?Tenant
    {
        return match (config('tms.mode')) {
            'single' => Tenant::query()->where('slug', config('tms.default_tenant'))->first(),
            'multi' => TenantDomain::query()->with('tenant')
                ->where('host', mb_strtolower($request->getHost()))->first()?->tenant,
            default => throw new LogicException('Valeur invalide pour tms.mode (attendu : single ou multi).'),
        };
    }

    private function unavailable(int $status, string $title, string $message): Response
    {
        return response()->view('tenancy.unavailable', ['title' => $title, 'message' => $message], $status);
    }
}
