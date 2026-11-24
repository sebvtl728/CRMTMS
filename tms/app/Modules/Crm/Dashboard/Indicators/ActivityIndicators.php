<?php

namespace App\Modules\Crm\Dashboard\Indicators;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\IndicatorValue;
use App\Modules\Crm\Dashboard\Sources;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Appels et RDV » (lot 1e): calls done (types meta.planning = call, by
 * done_at), meetings fixed (types meta.planning = meeting, by created_at,
 * like ActivityStats) and the response rate (calls done whose result is
 * meta.reached / calls done; null without any « joint » result).
 * Also: actions to do (not done, due before the end of the period: overdue
 * included), meetings done (meeting types, by done_at) and follow-ups to
 * come (not done, not a meeting, due from tomorrow to the end of the
 * period), on live fiches and opportunities like « À faire ».
 */
final class ActivityIndicators extends BaseIndicator
{
    private const TZ = 'Europe/Paris';

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('crm.calls', Indicator::WEEK, 'Appels faits', 'Actions de type appel faites dans la période', Permissions::ACTIVITIES_VIEW),
            new self('crm.meetings', Indicator::WEEK, 'Rendez-vous fixés', 'Rendez-vous créés dans la période', Permissions::ACTIVITIES_VIEW),
            new self('crm.response_rate', Indicator::WEEK, 'Taux de réponse', 'Appels faits avec un résultat « joint » / appels faits', Permissions::ACTIVITIES_VIEW),
            new self('crm.todo', Indicator::WEEK, 'Actions à faire', 'Actions non faites en retard ou prévues dans la période', Permissions::ACTIVITIES_VIEW),
            new self('crm.meetings_done', Indicator::WEEK, 'Rendez-vous faits', 'Rendez-vous réalisés dans la période', Permissions::ACTIVITIES_VIEW),
            new self('crm.follow_ups', Indicator::WEEK, 'Suivis à venir', 'Relances non faites (hors rendez-vous) prévues de demain à la fin de la période', Permissions::ACTIVITIES_VIEW),
        ];
    }

    public function value(DashboardFilters $f, User $user): IndicatorValue
    {
        $types = fn (string $kind) => ReferenceItem::withTrashed()->where('list', ReferenceList::ActionType)->where('meta->planning', $kind)->pluck('id')->all();
        $calls = Sources::activities($f, $user)->whereIn('activities.type_id', $types('call'))
            ->whereBetween('activities.done_at', [$f->start(), $f->end()]);

        $open = fn () => TodoQuery::onLiveFiches(Sources::activities($f, $user))->whereNull('activities.done_at')->whereNotNull('activities.due_at');

        return match ($this->key()) {
            'crm.todo' => new IndicatorValue($open()->where('activities.due_at', '<=', $f->end())->count(), IndicatorValue::COUNT),
            'crm.meetings_done' => new IndicatorValue(TodoQuery::onLiveFiches(Sources::activities($f, $user))->whereIn('activities.type_id', $types('meeting'))
                ->whereBetween('activities.done_at', [$f->start(), $f->end()])->count(), IndicatorValue::COUNT),
            'crm.follow_ups' => new IndicatorValue($open()->whereNotIn('activities.type_id', $types('meeting'))
                ->whereBetween('activities.due_at', [max($f->start(), CarbonImmutable::now()->setTimezone(self::TZ)->addDay()->startOfDay()), $f->end()])->count(), IndicatorValue::COUNT),
            'crm.meetings' => new IndicatorValue(Sources::activities($f, $user)->whereIn('activities.type_id', $types('meeting'))
                ->whereBetween('activities.created_at', [$f->start(), $f->end()])->count(), IndicatorValue::COUNT),
            'crm.response_rate' => $this->responseRate($calls),
            default => new IndicatorValue($calls->count(), IndicatorValue::COUNT),
        };
    }

    public function detailUrl(DashboardFilters $f): ?string
    {
        return in_array($this->key(), ['crm.todo', 'crm.follow_ups'], true) ? '/crm/todo' : null;
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
