<?php

use Illuminate\Support\Facades\Redis;

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
