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
        $who = $request->user()?->getAuthIdentifier() ?? $request->ip();

        return $name.'|'.$tenant.'|'.$who;
    }
}
