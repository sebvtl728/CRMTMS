<?php

namespace App\Modules\Core\Health;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * 60 requests/minute per IP. Uses the file cache store on purpose: the health
 * check must keep answering (503) when Redis or the database is down.
 */
class ThrottleHealth
{
    private const MAX_ATTEMPTS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = new RateLimiter(Cache::store('file'));
        $key = 'health:'.$request->ip();

        if ($limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json(['message' => 'Too Many Requests'], 429, [
                'Retry-After' => $limiter->availableIn($key),
            ]);
        }

        $limiter->hit($key, 60);

        return $next($request);
    }
}
