<?php

namespace App\Modules\Crm\Dashboard\Indicators;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\IndicatorValue;
use App\Modules\Crm\Dashboard\Sources;
use App\Modules\Crm\Permissions;

/** Quotes sent (sent_at) and accepted (accepted_on) in the period (lot 1e). */
final class QuoteIndicators extends BaseIndicator
{
    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('crm.quotes_sent', Indicator::WEEK, 'Devis envoyés', 'Devis envoyés dans la période', Permissions::QUOTES_VIEW),
            new self('crm.quotes_accepted', Indicator::WEEK, 'Devis acceptés', 'Devis acceptés dans la période', Permissions::QUOTES_VIEW),
        ];
    }

    public function value(DashboardFilters $f, User $user): IndicatorValue
    {
        $query = Sources::quotes($f, $user);

        return new IndicatorValue($this->key() === 'crm.quotes_sent'
            ? $query->whereBetween('quotes.sent_at', [$f->start(), $f->end()])->count()
            : $query->whereBetween('quotes.accepted_on', [$f->from->toDateString(), $f->to->toDateString()])->count(), IndicatorValue::COUNT);
    }

    public function detailUrl(DashboardFilters $f): string
    {
        return '/crm/quotes';
    }
}
