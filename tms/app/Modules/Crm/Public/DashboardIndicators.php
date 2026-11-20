<?php

namespace App\Modules\Crm\Public;

use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\Indicators;

/** How another module adds a card to the CRM dashboards (lot 1e: Sessions, the retention). */
final class DashboardIndicators
{
    public static function register(Indicator $indicator): void
    {
        Indicators::register($indicator);
    }
}
