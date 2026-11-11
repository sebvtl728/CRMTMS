<?php

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
