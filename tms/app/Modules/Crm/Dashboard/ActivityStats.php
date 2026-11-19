<?php

namespace App\Modules\Crm\Dashboard;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\Activities\WeekPlanning;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;

/**
 * « Actions » and « Rendez-vous » cards of « Accueil »: per Paris month over
 * the last 12, the actions created and the actions done, and the meetings
 * fixed (created) whatever their date (types flagged meta.planning
 * « meeting »). Within the view scope of the actions, collaborators left
 * out (an action counts for its scope, never for a collaborator), fiches in
 * the trash left out (as « À faire »).
 */
final class ActivityStats
{
    public const MONTHS = 12;

    /**
     * @return array{current: array{month: string, created: int, done: int, meetings: int}, previous: array{month: string, created: int, done: int, meetings: int}, months: list<array{key: string, label: string, created: int, done: int, meetings: int}>}
     */
    public static function for(User $user, CarbonImmutable $now): array
    {
        $tz = (string) config('app.timezone');
        $last = $now->setTimezone($tz)->startOfMonth();
        $first = $last->subMonths(self::MONTHS - 1);
        $from = $first->format('Y-m-d H:i:sP');
        $to = $last->addMonth()->format('Y-m-d H:i:sP');
        $base = fn () => TodoQuery::onLiveFiches(CrmVisibility::activities(Activity::query(), $user, collaborators: false))->toBase();

        // The session time zone is the app's (config/database.php): to_char gives Paris months.
        $created = $base()->where('activities.created_at', '>=', $from)->where('activities.created_at', '<', $to)
            ->selectRaw("to_char(activities.created_at, 'YYYY-MM') as m, count(*) as n")->groupBy('m')->pluck('n', 'm');
        $done = $base()->where('activities.done_at', '>=', $from)->where('activities.done_at', '<', $to)
            ->selectRaw("to_char(activities.done_at, 'YYYY-MM') as m, count(*) as n")->groupBy('m')->pluck('n', 'm');
        $types = WeekPlanning::meetingTypes();
        $meetings = $types === [] ? collect() : $base()->whereIn('activities.type_id', $types)
            ->where('activities.created_at', '>=', $from)->where('activities.created_at', '<', $to)
            ->selectRaw("to_char(activities.created_at, 'YYYY-MM') as m, count(*) as n")->groupBy('m')->pluck('n', 'm');

        $months = [];
        for ($month = $first; $month <= $last; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = [
                'key' => $key,
                'label' => ucfirst($month->locale('fr')->isoFormat('MMM')),
                'month' => ucfirst($month->locale('fr')->isoFormat('MMMM YYYY')),
                'created' => (int) ($created[$key] ?? 0),
                'done' => (int) ($done[$key] ?? 0),
                'meetings' => (int) ($meetings[$key] ?? 0),
            ];
        }
        $pick = fn (array $m) => ['month' => $m['month'], 'created' => $m['created'], 'done' => $m['done'], 'meetings' => $m['meetings']];

        return [
            'current' => $pick($months[self::MONTHS - 1]),
            'previous' => $pick($months[self::MONTHS - 2]),
            'months' => array_map(fn (array $m) => ['key' => $m['key'], 'label' => $m['label'], 'created' => $m['created'], 'done' => $m['done'], 'meetings' => $m['meetings']], $months),
        ];
    }
}
