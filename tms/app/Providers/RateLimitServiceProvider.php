<?php

namespace App\Providers;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * One named limiter per feature. Plain `throttle:N,1` builds its key from the
 * user alone, so every route using it would share a single counter: each
 * feature gets its own, per tenant + user (IP when anonymous).
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /** @var array<string, int> limiter name => requests per minute */
    public const LIMITS = [
        'crm-search' => 60,
        'crm-sirene' => 30,
        'import-upload' => 20,
        'import-status' => 120,
        'import-undo-preview' => 120,
        'email-verify' => 6,
        'email-verification-send' => 6,
        'activation' => 10,
        'calendar-feed' => 30,
    ];

    public function boot(): void
    {
        foreach (self::LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by(self::key($name, $request)));
        }
    }

    public static function key(string $name, Request $request): string
    {
        $tenant = app(TenantContext::class)->current()?->id ?: 'none';
        $who = $request->user()?->getAuthIdentifier() ?? self::feed($name, $request) ?? $request->ip();

        return $name.'|'.$tenant.'|'.$who;
    }

    /**
     * The iCal feed counts per link, not per address: the calendar servers
     * (Google, Microsoft) and an office behind one NAT share a few IPs. The
     * hash of the token, never the token itself, goes into the cache key.
     */
    private static function feed(string $name, Request $request): ?string
    {
        $token = $request->route('token');

        return $name === 'calendar-feed' && is_string($token) ? 'feed:'.hash('sha256', $token) : null;
    }
}
