<?php

namespace App\Modules\Crm\Dashboard\Indicators;

use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;

/** Common shape of the CRM cards: a key, a tab, a label, a formula and a right. */
abstract class BaseIndicator implements Indicator
{
    public function __construct(
        private readonly string $key,
        private readonly string $tab,
        private readonly string $label,
        private readonly string $formula,
        private readonly string $permission,
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

    public function detailUrl(DashboardFilters $filters): ?string
    {
        return null;
    }

    /** round(100 × $part / $whole, 1), null over nothing. */
    protected static function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(100 * $part / $whole, 1);
    }
}
