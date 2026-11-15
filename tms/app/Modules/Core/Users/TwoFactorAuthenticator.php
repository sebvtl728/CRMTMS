<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Tenancy\TenantContext;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238, 30 s steps, 6 digits).
 *
 *  - the secret is stored encrypted (User cast), generated pending and only
 *    active once confirmed with a valid code;
 *  - 8 single-use recovery codes, shown once, stored as sha256 hashes inside
 *    an encrypted column;
 *  - a TOTP time step is accepted at most once per user (no replay).
 */
class TwoFactorAuthenticator
{
    public const RECOVERY_CODES = 8;

    /** Accepted clock drift, in 30 s steps on each side. */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * 2FA is mandatory for one of the user's roles (always for admin_organisme
     * and direction, by choice for the others), or imposed by the tenant
     * setting security.force_2fa. The roles are read afresh: a role switched
     * to mandatory 2FA applies at the user's next request.
     */
    public function isRequiredFor(User $user): bool
    {
        if ($user->roles()->get()->contains(fn ($role) => $role instanceof RoleModel && $role->requires2fa())) {
            return true;
        }

        $settings = app(TenantContext::class)->current()->settings ?? [];

        return data_get($settings, 'security.force_2fa') === true;
    }

    /**
     * Pending (unconfirmed) secret of the user, created if missing. Reused
     * across page loads so a QR code already scanned stays valid.
     */
    public function pendingSecret(User $user): string
    {
        if ($user->two_factor_secret === null) {
            $user->forceFill(['two_factor_secret' => $this->google2fa->generateSecretKey(32)])->save();
        }

        return (string) $user->two_factor_secret;
    }

    public function qrCodeSvg(User $user): string
    {
        $url = $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, (string) $user->two_factor_secret);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(17, 24, 39))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1)); // without the XML declaration
    }

    /**
     * Confirms the pending secret with a code from the app. Returns the plain
     * recovery codes (to show once) or null if the code is invalid.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || ! $this->verifyCode($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    /**
     * Checks a TOTP code and consumes its time step (atomically: two requests
     * cannot both use the same code).
     */
    public function verifyCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($user->two_factor_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        // With an old step given, google2fa returns the matching step (int).
        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_used_step ?? 0, self::WINDOW);

        if (! is_int($step)) {
            return false;
        }

        $consumed = User::query()->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', $step))
            ->update(['two_factor_last_used_step' => $step]);

        if ($consumed !== 1) {
            return false;
        }

        $user->two_factor_last_used_step = $step;
        $user->syncOriginalAttribute('two_factor_last_used_step');

        return true;
    }

    /**
     * Uses (and removes) a recovery code. The codes are re-read from the row
     * locked for update, so concurrent requests never both use the same code
     * nor write back a list that resurrects a code used meanwhile.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = self::hashRecoveryCode($code);

        return DB::transaction(function () use ($user, $hash): bool {
            $locked = User::query()->lockForUpdate()->find($user->getKey());

            if (! $locked instanceof User) {
                return false;
            }

            $hashes = $locked->two_factor_recovery_codes ?? [];
            $used = false;

            foreach ($hashes as $i => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($hashes[$i]);
                    $used = true;

                    break;
                }
            }

            if ($used) {
                $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
            }

            // Keep the caller's instance in step with the database.
            $user->forceFill(['two_factor_recovery_codes' => $locked->two_factor_recovery_codes]);
            $user->syncOriginalAttribute('two_factor_recovery_codes');

            return $used;
        });
    }

    /**
     * @return list<string> the new plain codes (the previous ones stop working)
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];

        while (count($codes) < self::RECOVERY_CODES) {
            $codes[] = Str::lower(Str::random(10).'-'.Str::random(10));
        }

        $user->forceFill(['two_factor_recovery_codes' => array_map(self::hashRecoveryCode(...), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', Str::lower(trim($code)));
    }
}
