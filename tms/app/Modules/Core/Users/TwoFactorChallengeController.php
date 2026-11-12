<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Second login step (/two-factor-challenge) for accounts with confirmed 2FA.
 *
 * The password step (AuthenticatedSessionController) does not log the user
 * in: it stores the pending login in the session (login.*). This step checks
 * a TOTP or recovery code, then logs in and regenerates the session.
 * Throttled per pending login (RateLimiter "two-factor", 5 per minute).
 */
class TwoFactorChallengeController extends Controller
{
    /** A pending login must be completed within this delay. */
    public const PENDING_MINUTES = 10;

    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    /**
     * Stores the pending login after a valid password.
     */
    public static function begin(Request $request, User $user, bool $remember): void
    {
        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $remember,
            'login.expires_at' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return to_route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return to_route('login')->with('status', 'Votre connexion a expiré. Saisissez à nouveau vos identifiants.');
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        if (filled($validated['code'] ?? null)) {
            if (! $this->twoFactor->verifyCode($user, $validated['code'])) {
                throw ValidationException::withMessages(['code' => 'Le code de vérification est invalide.']);
            }
        } elseif (! $this->twoFactor->useRecoveryCode($user, (string) $validated['recovery_code'])) {
            throw ValidationException::withMessages(['recovery_code' => 'Le code de récupération est invalide.']);
        }

        // Remember-me is not offered (it would outlive the idle timeout).
        $request->session()->forget(['login.id', 'login.remember', 'login.expires_at']);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * The user of the pending login, if still valid: not expired, and still an
     * active account of this tenant (the provider ignores deactivated users).
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $expiresAt = (int) $request->session()->get('login.expires_at', 0);

        if (! is_string($id) || $expiresAt < now()->getTimestamp()) {
            $request->session()->forget(['login.id', 'login.remember', 'login.expires_at']);

            return null;
        }

        $user = Auth::guard('web')->getProvider()->retrieveById($id);

        if (! $user instanceof User) {
            $request->session()->forget(['login.id', 'login.remember', 'login.expires_at']);

            return null;
        }

        return $user;
    }
}
