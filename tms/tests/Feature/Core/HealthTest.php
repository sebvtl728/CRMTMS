<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

// The throttle counters live in the file cache store, which persists between tests.
beforeEach(fn () => Cache::store('file')->flush());

it('reports healthy services', function () {
    $this->getJson('/health')->assertOk()
        ->assertJson(['status' => 'ok', 'db' => 'ok', 'redis' => 'ok', 'version' => config('app.version')]);
});

it('does not require authentication', function () {
    $this->assertGuest();
    $this->getJson('/health')->assertOk();
});

it('answers 503 and names the failing service when redis is down', function () {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis down'));

    $this->getJson('/health')->assertStatus(503)
        ->assertJson(['status' => 'error', 'db' => 'ok', 'redis' => 'error']);
});

it('is rate limited to 60 requests per minute', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/health')->assertOk();
    }

    $this->getJson('/health')->assertStatus(429);
});

it('sets no session cookie', function () {
    $response = $this->getJson('/health')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

it('still answers 503 JSON when the session store (redis) is unreachable', function () {
    config([
        'session.driver' => 'redis',
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
    ]);
    Redis::purge();

    $this->getJson('/health')->assertStatus(503)
        ->assertJson(['status' => 'error', 'db' => 'ok', 'redis' => 'error']);
});
