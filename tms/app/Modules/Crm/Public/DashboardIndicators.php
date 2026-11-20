<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\CallableIndicator;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\Indicators;
use Closure;

/**
 * How another module adds a card to the CRM dashboards (lot 1e: Sessions,
 * the retention). define(): plain arrays in and out (filters: tab, preset,
 * from, to as Y-m-d, das, target organisation|person, responsible; result:
 * value, unit count|cents|percent|days, breakdown, note).
 */
final class DashboardIndicators
{
    public const WEEK = Indicator::WEEK;

    public const MONTH = Indicator::MONTH;

    public static function register(Indicator $indicator): void
    {
        Indicators::register($indicator);
    }

    /**
     * @param  Closure(array<string, string|null>, User): array{value: int|float|null, unit: string, breakdown?: list<array{label: string, value: int|float|null}>|null, note?: string|null}  $compute
     */
    public static function define(string $key, string $tab, string $label, string $formula, string $permission, Closure $compute, ?string $detailUrl = null): void
    {
        Indicators::register(new CallableIndicator($key, $tab, $label, $formula, $permission, $compute, $detailUrl));
    }
}
