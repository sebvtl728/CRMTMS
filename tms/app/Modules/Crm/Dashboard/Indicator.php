<?php

namespace App\Modules\Crm\Dashboard;

use App\Modules\Core\Users\User;

/**
 * A card of the « Tableaux de bord » page (lot 1e): its tab (week | month),
 * the right that shows it (its rows follow that right's scope), its formula
 * in one French line, its value over the filters and the list behind it.
 */
interface Indicator
{
    public const WEEK = 'week';

    public const MONTH = 'month';

    public function key(): string;

    public function tab(): string;

    public function label(): string;

    public function formula(): string;

    public function permission(): string;

    public function value(DashboardFilters $filters, User $user): IndicatorValue;

    public function detailUrl(DashboardFilters $filters): ?string;
}
