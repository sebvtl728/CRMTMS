<?php

/*
 * Helpers of the Outlook (Microsoft 365) tests: Graph and the token
 * endpoint faked, a configured connector, an active link. Included with
 * require_once.
 */

use App\Modules\Connectors\Calendar\CalendarLink;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const M365_TENANT = '72f988bf-86f1-41af-91ab-2d7cd011db47';
const M365_CLIENT = '6731de76-14a6-49ae-97bc-6eba6914391e';
const GRAPH_EVENTS = 'https://graph.microsoft.com/v1.0/me/events';
const M365_TOKEN_URL = 'https://login.microsoftonline.com/'.M365_TENANT.'/oauth2/v2.0/token';

/** A fresh fake (Http::fake() alone keeps the first matching stub), no stray request. */
function m365Http(array $stubs = []): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake($stubs);
}

/** A test secret generated per run: never a value written in the repository. */
function m365Config(bool $enabled = true): ConnectorConfig
{
    $config = ConnectorConfig::query()->firstOrNew(['connector_key' => 'microsoft365', 'company_id' => null]);
    $config->fill([
        'enabled' => $enabled,
        'settings' => ['tenant' => M365_TENANT, 'client_id' => M365_CLIENT],
        'secrets' => ['client_secret' => 'test-'.Str::random(32)],
    ])->save();

    return $config;
}

function m365Link(User $user, array $attributes = []): CalendarLink
{
    $link = new CalendarLink(['user_id' => $user->id]);
    $link->forceFill([
        'status' => CalendarLink::ACTIVE,
        'access_token' => 'access-'.Str::random(20),
        'refresh_token' => 'refresh-'.Str::random(20),
        'expires_at' => now()->addHour(),
        'connected_at' => now(),
        ...$attributes,
    ])->save();

    return $link;
}

function m365At(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/** @return list<Request> */
function graphRequests(): array
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://graph.microsoft.com/'))->map(fn (array $pair) => $pair[0])->values()->all();
}

function graphCreated(string $id = 'AAMkEvent1'): array
{
    return [GRAPH_EVENTS => Http::response(['id' => $id], 201)];
}
