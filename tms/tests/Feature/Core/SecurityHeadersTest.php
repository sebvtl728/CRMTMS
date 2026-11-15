<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('sends security headers', function () {
    $this->get('/login')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeaderMissing('X-Powered-By');

    expect($this->get('/login')->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'");
});

it('uses a per-response nonce in the CSP and on the script tags', function () {
    $response = $this->get('/login');
    $csp = $response->headers->get('Content-Security-Policy');

    preg_match("/script-src[^;]*'nonce-([^']+)'/", $csp, $matches);

    expect($matches[1] ?? null)->not->toBeEmpty();
    expect($response->getContent())->toContain('nonce="'.$matches[1].'"');
    preg_match('/script-src[^;]*/', $csp, $scriptSrc);
    expect($scriptSrc[0])->not->toContain('unsafe-inline')->not->toContain('unsafe-eval');
});

it('sends HSTS only over HTTPS', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

// Behind Caddy (TRUSTED_PROXIES=172.29.250.10 in phpunit.xml).
it('honours X-Forwarded-Proto and the client IP from the trusted proxy only', function () {
    $forwarded = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];

    $this->withServerVariables(['REMOTE_ADDR' => '172.29.250.10'] + $forwarded)
        ->get('/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    $request = Request::create('/login', 'GET', server: ['REMOTE_ADDR' => '172.29.250.10'] + $forwarded);
    $request->setTrustedProxies(['172.29.250.10'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    expect($request->ip())->toBe('203.0.113.7')->and($request->isSecure())->toBeTrue();
});

it('ignores forged X-Forwarded-* headers from any other address', function () {
    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
    ])->get('/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('uses the forwarded client IP for the /health throttle key', function () {
    // Unique client IPs instead of flushing the shared file cache.
    $client = '203.0.113.'.random_int(1, 254);
    $other = '198.51.100.'.random_int(1, 254);
    $proxy = ['REMOTE_ADDR' => '172.29.250.10', 'HTTP_X_FORWARDED_FOR' => $client];

    foreach (range(1, 60) as $_) {
        $this->withServerVariables($proxy)->getJson('/health')->assertOk();
    }
    $this->withServerVariables($proxy)->getJson('/health')->assertStatus(429);

    // Another client behind the same proxy is not throttled.
    $this->withServerVariables(['REMOTE_ADDR' => '172.29.250.10', 'HTTP_X_FORWARDED_FOR' => $other])
        ->getJson('/health')->assertOk();
});

it('adds the policy of a response as a second header, which can only tighten the global one', function () {
    Route::middleware('web')->get('/_test/csp', fn () => response('x', 200, ['Content-Security-Policy' => 'script-src *']));

    $policies = $this->get('/_test/csp')->assertOk()->headers->all('Content-Security-Policy');

    expect($policies)->toHaveCount(2)
        ->and($policies[0])->toContain("script-src 'self'")->not->toContain('script-src *')
        ->and($policies[1])->toBe('script-src *');
});

it('sends a single global policy when the response sets none', function () {
    expect($this->get('/login')->headers->all('Content-Security-Policy'))->toHaveCount(1);
});
