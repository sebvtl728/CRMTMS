<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two-factor enrolment of the authenticated user (/two-factor/*).
 */
class TwoFactorController extends Controller
{
    /** Session flash key of the plain recovery codes (shown once). */
    public const RECOVERY_CODES_FLASH = 'two-factor.recovery-codes';

    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $enabled = $user->hasTwoFactorEnabled();

        if (! $enabled) {
            $this->twoFactor->pendingSecret($user);
        }

        return Inertia::render('auth/two-factor-setup', [
            'enabled' => $enabled,
            'required' => $this->twoFactor->isRequiredFor($user),
            'qrCodeSvg' => $enabled ? null : $this->twoFactor->qrCodeSvg($user),
            'secret' => $enabled ? null : trim(chunk_split((string) $user->two_factor_secret, 4, ' ')),
            'recoveryCodes' => $request->session()->get(self::RECOVERY_CODES_FLASH),
            'recoveryCodesLeft' => $enabled ? count($user->two_factor_recovery_codes ?? []) : null,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return to_route('two-factor.setup');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);
        $codes = $this->twoFactor->confirm($user, $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Le code de vérification est invalide.']);
        }

        // Security level of the session changed.
        $request->session()->regenerate();
        $user->revokeOtherSessions($request->session()->getId());

        return to_route('two-factor.setup')
            ->with(self::RECOVERY_CODES_FLASH, $codes)
            ->with('status', 'La double authentification est activée.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 404);

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $user->revokeOtherSessions($request->session()->getId());

        return to_route('two-factor.setup')
            ->with(self::RECOVERY_CODES_FLASH, $codes)
            ->with('status', 'De nouveaux codes de récupération ont été générés ; les anciens ne fonctionnent plus.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($this->twoFactor->isRequiredFor($user), 403, 'La double authentification est obligatoire pour votre compte.');

        $this->twoFactor->disable($user);
        $user->revokeOtherSessions($request->session()->getId());

        return to_route('two-factor.setup')->with('status', 'La double authentification est désactivée.');
    }
}
