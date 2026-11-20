<?php

namespace App\Modules\Crm\Dashboard;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Cache;

/**
 * Registry of the dashboard cards (lot 1e): the CRM registers its own, other
 * modules theirs through Crm\Public\DashboardIndicators (Sessions: the
 * retention). A card shows only to a user holding its right; its current
 * and previous values are cached 60 s under the tenant, per user and filters.
 */
final class Indicators
{
    public const TTL = 60;

    /** @var array<string, Indicator> */
    private static array $indicators = [];

    public static function register(Indicator $indicator): void
    {
        self::$indicators[$indicator->key()] = $indicator;
    }

    public static function forget(string $key): void
    {
        unset(self::$indicators[$key]);
    }

    public static function get(string $key): ?Indicator
    {
        return self::$indicators[$key] ?? null;
    }

    /**
     * @return list<array{key: string, label: string, formula: string, value: int|float|null, previous: int|float|null, delta: int|float|null, unit: string, breakdown: list<array<string, mixed>>|null, target_cents: int|null, note: string|null, detail_url: string|null}>
     */
    public static function for(string $tab, DashboardFilters $filters, User $user): array
    {
        $tenant = app(TenantContext::class)->id();
        $cards = [];
        foreach (self::$indicators as $indicator) {
            if ($indicator->tab() !== $tab || ! $user->can($indicator->permission())) {
                continue;
            }
            [$now, $before] = Cache::remember("tenant:{$tenant}:dashboard:{$user->id}:{$filters->key()}:{$indicator->key()}", self::TTL,
                fn () => [$indicator->value($filters, $user)->toArray(), $indicator->value($filters->previous(), $user)->toArray()]);
            $cards[] = [
                'key' => $indicator->key(),
                'label' => $indicator->label(),
                'formula' => $indicator->formula(),
                ...$now,
                'previous' => $before['value'],
                'delta' => $now['value'] === null || $before['value'] === null ? null : round($now['value'] - $before['value'], 2) + 0,
                'detail_url' => $indicator->detailUrl($filters),
            ];
        }

        return $cards;
    }
}
