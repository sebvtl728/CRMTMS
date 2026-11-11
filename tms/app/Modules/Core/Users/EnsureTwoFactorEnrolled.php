<?php

namespace App\Modules\Core\Users;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-group middleware: an authenticated user whose 2FA is mandatory (role
 * admin_organisme / direction, or tenant setting security.force_2fa) and not
 * confirmed yet is sent to /two-factor/setup on every route except the
 * enrolment routes, the login challenge and logout.
 */
class EnsureTwoFactorEnrolled
{
    /** @var list<string> */
    private const EXEMPT_ROUTES = [
        'two-factor.setup',
        'two-factor.confirm',
        'two-factor.challenge',
        'two-factor.challenge.store',
        'logout',
    ];

    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasTwoFactorEnabled()
            && ! $request->routeIs(...self::EXEMPT_ROUTES)
            && $this->twoFactor->isRequiredFor($user)) {
            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
