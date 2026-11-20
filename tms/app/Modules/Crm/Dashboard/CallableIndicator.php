<?php

namespace App\Modules\Crm\Dashboard;

use App\Modules\Core\Users\User;
use Closure;

/**
 * A card defined by another module through Crm\Public\DashboardIndicators
 * (lot 1e): its value is computed from plain arrays, so the module never
 * names a class of the CRM dashboards.
 */
final class CallableIndicator implements Indicator
{
    /**
     * @param  Closure(array{tab: string, preset: string, from: string, to: string, das: ?string, target: ?string, responsible: ?string}, User): array{value: int|float|null, unit: string, breakdown?: list<array{label: string, value: int|float|null}>|null, note?: string|null}  $compute
     */
    public function __construct(
        private readonly string $key,
        private readonly string $tab,
        private readonly string $label,
        private readonly string $formula,
        private readonly string $permission,
        private readonly Closure $compute,
        private readonly ?string $detailUrl = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function tab(): string
    {
        return $this->tab;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function formula(): string
    {
        return $this->formula;
    }

    public function permission(): string
    {
        return $this->permission;
    }

    public function value(DashboardFilters $filters, User $user): IndicatorValue
    {
        $out = ($this->compute)($filters->toArray(), $user);

        return new IndicatorValue($out['value'], $out['unit'], $out['breakdown'] ?? null, note: $out['note'] ?? null);
    }

    public function detailUrl(DashboardFilters $filters): ?string
    {
        return $this->detailUrl;
    }
}
