<?php

namespace App\Modules\Crm\Dashboard\Indicators;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\IndicatorValue;
use App\Modules\Crm\Dashboard\Sources;
use App\Modules\Crm\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Appels et RDV » (lot 1e): calls done (types meta.planning = call, by
 * done_at), meetings fixed (types meta.planning = meeting, by created_at,
 * like ActivityStats) and the response rate (calls done whose result is
 * meta.reached / calls done; null without any « joint » result).
 */
final class ActivityIndicators extends BaseIndicator
{
    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('crm.calls', Indicator::WEEK, 'Appels faits', 'Actions de type appel faites dans la période', Permissions::ACTIVITIES_VIEW),
            new self('crm.meetings', Indicator::WEEK, 'Rendez-vous fixés', 'Rendez-vous créés dans la période', Permissions::ACTIVITIES_VIEW),
            new self('crm.response_rate', Indicator::WEEK, 'Taux de réponse', 'Appels faits avec un résultat « joint » / appels faits', Permissions::ACTIVITIES_VIEW),
        ];
    }

    public function value(DashboardFilters $f, User $user): IndicatorValue
    {
        $types = fn (string $kind) => ReferenceItem::withTrashed()->where('list', ReferenceList::ActionType)->where('meta->planning', $kind)->pluck('id')->all();
        $calls = Sources::activities($f, $user)->whereIn('activities.type_id', $types('call'))
            ->whereBetween('activities.done_at', [$f->start(), $f->end()]);

        return match ($this->key()) {
            'crm.meetings' => new IndicatorValue(Sources::activities($f, $user)->whereIn('activities.type_id', $types('meeting'))
                ->whereBetween('activities.created_at', [$f->start(), $f->end()])->count(), IndicatorValue::COUNT),
            'crm.response_rate' => $this->responseRate($calls),
            default => new IndicatorValue($calls->count(), IndicatorValue::COUNT),
        };
    }

    /** @param  Builder<Activity>  $calls */
    private function responseRate($calls): IndicatorValue
    {
        $reached = ReferenceItem::withTrashed()->where('list', ReferenceList::ActionResult)->where('meta->reached', true)->pluck('id')->all();
        if ($reached === []) {
            return new IndicatorValue(null, IndicatorValue::PERCENT, note: 'Aucun résultat d\'action ne compte comme « joint » (Administration › Référentiels).');
        }

        return new IndicatorValue(self::rate((clone $calls)->whereIn('activities.result_id', $reached)->count(), $calls->count()), IndicatorValue::PERCENT);
    }
}
