<?php

namespace App\Modules\Core\Security;

use App\Modules\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-group middleware (after the session): an authenticated session idle for
 * longer than the organisme's idle_timeout_minutes is logged out and sent to
 * the login page. The last activity is kept in the session. /health lives
 * outside the web group and is never concerned.
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'last_activity_at';

    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $timeout = SecuritySettings::for($this->tenants->current())['idle_timeout_minutes'];
        $last = $request->session()->get(self::SESSION_KEY);

        if (is_int($last) && now()->timestamp - $last > $timeout * 60) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Votre session a expiré après une période d\'inactivité. Veuillez vous reconnecter.');
        }

        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return $next($request);
    }
}
