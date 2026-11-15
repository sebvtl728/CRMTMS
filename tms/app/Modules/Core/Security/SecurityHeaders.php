<?php

namespace App\Modules\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Must run before the view is rendered so @vite/@routes tags carry the nonce.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // The global policy always applies. A response's own policy (e.g.
        // « sandbox » for an uploaded image) is sent as a second header: the
        // browser enforces both, so it can only tighten, never relax.
        $own = array_values(array_filter($response->headers->all('Content-Security-Policy'), fn ($v) => is_string($v) && $v !== ''));
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        foreach ($own as $policy) {
            $response->headers->set('Content-Security-Policy', $policy, false);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $dev = $this->devServerOrigins();

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$dev['http'],
            "style-src 'self' 'nonce-{$nonce}'".$dev['http'],
            // React/Radix set style="" attributes at runtime; inline style attributes cannot carry a nonce.
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'".$dev['http'],
            "connect-src 'self'".$dev['http'].$dev['ws'],
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directives);
    }

    /**
     * While the Vite dev server runs (public/hot exists), allow its origin.
     *
     * @return array{http: string, ws: string}
     */
    private function devServerOrigins(): array
    {
        $hot = public_path('hot');

        if (! is_file($hot)) {
            return ['http' => '', 'ws' => ''];
        }

        $url = trim((string) file_get_contents($hot));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return ['http' => '', 'ws' => ''];
        }

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return ['http' => " http://{$authority}", 'ws' => " ws://{$authority}"];
    }
}
