<?php

namespace App\Modules\Crm\Dashboard;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\ActivityRows;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\Activities\WeekPlanning;
use App\Modules\Crm\Opportunities\OpportunityListQuery;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\QuoteSuccessRate;
use App\Modules\Crm\Revenue\DasRevenue;
use App\Modules\Crm\Revenue\Period;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;

/**
 * The commercial blocks of « Accueil », always within what the user may
 * see (each block within the view scope of its domain):
 * - « Mes relances »: his own actions overdue and due today (as « À faire »);
 * - « Pipeline pondéré du mois »: weighted amount of the open opportunities
 *   expected to close in the current Paris month (as the list filtered so);
 * - « Devis »: quotes sent this Paris month, accepted among them, the rate;
 * - « Prochains rendez-vous »: his next meetings to do (types flagged
 *   meta.planning « meeting »), without report.
 */
final class CommercialSummary
{
    /** Meetings listed, at most. */
    public const MEETINGS = 5;

    /**
     * @return array{follow_ups: array{overdue: int, today: int}, pipeline: array{weighted_amount: string, count: int, month: string, close_from: string, close_to: string}, quotes: array{sent: int, accepted: int, rate: float|null, month: string}, meetings: list<array<string, mixed>>, revenue: array{total_cents: int, target_cents: int|null, targeted_total_cents: int|null, rate: float|null, month: string, own: bool}|null}
     */
    public static function for(User $user, CarbonImmutable $now): array
    {
        return [
            'follow_ups' => TodoQuery::counts($user, $now),
            'pipeline' => self::pipeline($user, $now),
            'quotes' => self::quotes($user, $now),
            'meetings' => self::meetings($user, $now),
            'revenue' => self::revenue($user, $now),
        ];
    }

    /**
     * Signed revenue of the Paris month within the user's scope (null without crm.revenue.view).
     * « Ses fiches » (own) has no target to compare with. The rate compares
     * the target with the revenue of the DAS that have one (targeted_total_cents).
     *
     * @return array{total_cents: int, target_cents: int|null, targeted_total_cents: int|null, rate: float|null, month: string, own: bool}|null
     */
    private static function revenue(User $user, CarbonImmutable $now): ?array
    {
        $scope = Scopes::of($user, Permissions::REVENUE_VIEW);
        if ($scope === null) {
            return null;
        }

        $month = Period::current('month', $now);
        $totals = DasRevenue::for($user, $month)['totals'];
        $own = $scope === AccessScope::Own;

        return [
            'total_cents' => $totals['total_cents'],
            'target_cents' => $own ? null : $totals['target_cents'],
            'targeted_total_cents' => $own || $totals['target_cents'] === null ? null : $totals['targeted_total_cents'],
            'rate' => $own ? null : $totals['rate'],
            'month' => $month->label(),
            'own' => $own,
        ];
    }

    /**
     * Quotes sent this Paris month, accepted among them, and the rate.
     *
     * @return array{sent: int, accepted: int, rate: float|null, month: string}
     */
    private static function quotes(User $user, CarbonImmutable $now): array
    {
        $month = QuoteSuccessRate::period('month', $now);

        return [...QuoteSuccessRate::for($user, $month['from'], $month['to']), 'month' => $month['label']];
    }

    /**
     * @return array{weighted_amount: string, count: int, month: string, close_from: string, close_to: string}
     */
    private static function pipeline(User $user, CarbonImmutable $now): array
    {
        $local = $now->setTimezone((string) config('app.timezone'));
        $from = $local->startOfMonth()->toDateString();
        $to = $local->endOfMonth()->toDateString();

        $filters = OpportunityListQuery::filters(['close_from' => $from, 'close_to' => $to]);
        $row = OpportunityListQuery::build($filters, $user)
            ->whereNotIn('opportunities.stage_id', self::closedStages())
            ->reorder()->toBase()
            ->selectRaw('count(*) as count, coalesce(sum(opportunities.weighted_amount), 0) as weighted')
            ->first();

        return [
            'weighted_amount' => number_format((float) ($row->weighted ?? 0), 2, '.', ''),
            'count' => (int) ($row->count ?? 0),
            'month' => ucfirst($local->locale('fr')->isoFormat('MMMM YYYY')),
            'close_from' => $from,
            'close_to' => $to,
        ];
    }

    /**
     * Won and lost stages (inactive ones too: old opportunities keep them).
     *
     * @return list<string>
     */
    private static function closedStages(): array
    {
        return ReferenceItem::withTrashed()->where('list', ReferenceList::PipelineStage)->get(['id', 'meta'])
            ->filter(fn (ReferenceItem $stage) => OpportunityStage::outcome($stage) !== null)
            ->pluck('id')->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function meetings(User $user, CarbonImmutable $now): array
    {
        $types = WeekPlanning::meetingTypes();
        if ($types === []) {
            return [];
        }

        $tz = (string) config('app.timezone');

        $rows = TodoQuery::onLiveFiches(CrmVisibility::activities(Activity::query(), $user))
            ->where('responsible_user_id', $user->id)
            ->whereIn('type_id', $types)
            ->whereNull('done_at')
            ->where('due_at', '>=', $now->format('Y-m-d H:i:sP'))
            ->with(['type', 'organisation:id,name', 'person:id,first_name,last_name', 'opportunity:id,title'])
            ->orderBy('due_at')->orderBy('id')
            ->limit(self::MEETINGS)
            ->get();
        // An action handed to him may hang on a fiche he may not open.
        $rights = ActivityRows::rights($rows, $user);

        return $rows->map(function (Activity $activity) use ($now, $tz, $rights): array {
            $at = CarbonImmutable::instance($activity->due_at ?? $now)->setTimezone($tz);

            return [
                'id' => $activity->id,
                'type' => $activity->type?->label,
                'at' => $at->toIso8601String(),
                'day' => ucfirst($at->locale('fr')->isoFormat('dddd D MMMM')),
                'time' => $at->format('H:i'),
                'location' => $activity->location,
                'organisation' => $activity->organisation === null ? null : ['id' => $activity->organisation->id, 'name' => $activity->organisation->name],
                'person' => $activity->person === null ? null : ['id' => $activity->person->id, 'name' => $activity->person->fullName()],
                'opportunity' => $activity->opportunity === null ? null : ['id' => $activity->opportunity->id, 'name' => $activity->opportunity->title],
                'fiche_visible' => $rights[(string) $activity->id]['fiche_visible'],
            ];
        })->values()->all();
    }
}
