<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The OAuth « state » and PKCE verifier of one « Connecter Outlook ».
 *
 * The state is signed (HMAC with a key derived from APP_KEY) and names the
 * organisme, the user, a nonce and its expiry (10 minutes). The nonce and
 * the PKCE verifier stay in the user's session; consuming the state pulls
 * them, so a state is good for one callback only, in the session that
 * started it, for the same user and organisme.
 */
final class OutlookOAuthState
{
    public const TTL_SECONDS = 600;

    private const SESSION_KEY = 'outlook_oauth';

    /**
     * @return array{state: string, challenge: string}
     */
    public static function issue(Request $request, User $user): array
    {
        $nonce = Str::random(40);
        $verifier = self::base64url(random_bytes(64)); // 86 characters (RFC 7636: 43 to 128)
        $request->session()->put(self::SESSION_KEY, ['nonce' => $nonce, 'verifier' => $verifier]);

        $payload = self::base64url((string) json_encode([
            't' => app(TenantContext::class)->id(),
            'u' => $user->id,
            'n' => $nonce,
            'e' => now()->getTimestamp() + self::TTL_SECONDS,
        ]));

        return [
            'state' => $payload.'.'.self::sign($payload),
            'challenge' => self::base64url(hash('sha256', $verifier, true)),
        ];
    }

    /**
     * The PKCE verifier when $state is valid for this session, user and
     * organisme, else null. Whatever the outcome, the pending connection
     * of the session is forgotten.
     */
    public static function consume(Request $request, User $user, mixed $state): ?string
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        if (! is_array($pending) || ! is_string($pending['nonce'] ?? null) || ! is_string($pending['verifier'] ?? null) || ! is_string($state)) {
            return null;
        }

        $parts = explode('.', $state);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($claims)) {
            return null;
        }

        $valid = ($claims['t'] ?? null) === app(TenantContext::class)->id()
            && ($claims['u'] ?? null) === $user->id
            && is_string($claims['n'] ?? null) && hash_equals($pending['nonce'], $claims['n'])
            && is_int($claims['e'] ?? null) && $claims['e'] >= now()->getTimestamp();

        return $valid ? $pending['verifier'] : null;
    }

    private static function sign(string $payload): string
    {
        $key = hash_hmac('sha256', 'outlook-oauth-state', (string) config('app.key'), true);

        return self::base64url(hash_hmac('sha256', $payload, $key, true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
