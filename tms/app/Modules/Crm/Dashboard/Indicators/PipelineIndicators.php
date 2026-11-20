<?php

namespace App\Modules\Crm\Dashboard\Indicators;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\IndicatorValue;
use App\Modules\Crm\Dashboard\Sources;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Settings\CrmFollowUpSettings;
use Illuminate\Support\Facades\DB;

/**
 * Opportunity cards of the week (lot 1e): new leads (fiches whose first
 * opportunity at or beyond the threshold stage got there in the period),
 * the weighted pipeline and the opportunities to close (open, expected
 * close in the period).
 */
final class PipelineIndicators extends BaseIndicator
{
    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('crm.leads', Indicator::WEEK, 'Nouveaux leads', 'Fiches dont une opportunité atteint pour la première fois l\'étape seuil (réglages du CRM) dans la période', Permissions::OPPORTUNITIES_VIEW),
            new self('crm.weighted_pipeline', Indicator::WEEK, 'Pipeline pondéré', 'Somme montant × probabilité des opportunités ouvertes à clôturer dans la période', Permissions::OPPORTUNITIES_VIEW),
            new self('crm.closing', Indicator::WEEK, 'Opportunités à clôturer', 'Opportunités ouvertes dont la clôture prévue tombe dans la période', Permissions::OPPORTUNITIES_VIEW),
        ];
    }

    public function value(DashboardFilters $f, User $user): IndicatorValue
    {
        if ($this->key() === 'crm.leads') {
            return new IndicatorValue($this->leads($f, $user), IndicatorValue::COUNT);
        }
        $open = Sources::opportunities($f, $user)->whereNull('opportunities.won_on')->whereNull('opportunities.lost_on')
            ->whereBetween('opportunities.expected_close_on', [$f->from->toDateString(), $f->to->toDateString()]);

        return $this->key() === 'crm.closing'
            ? new IndicatorValue($open->count(), IndicatorValue::COUNT)
            : new IndicatorValue((int) $open->sum(DB::raw('round(coalesce(opportunities.weighted_amount, 0) * 100)')), IndicatorValue::CENTS);
    }

    public function detailUrl(DashboardFilters $f): ?string
    {
        return $this->key() === 'crm.leads' ? null
            : Sources::opportunityListUrl($f, ['close_from' => $f->from->toDateString(), 'close_to' => $f->to->toDateString()]);
    }

    private function leads(DashboardFilters $f, User $user): int
    {
        $threshold = ReferenceItem::query()->whereKey(CrmFollowUpSettings::leadStageId())->value('position');
        if ($threshold === null) {
            return 0;
        }
        $stages = ReferenceItem::withTrashed()->where('list', ReferenceList::PipelineStage)->where('position', '>=', $threshold)->select('id');
        $firsts = DB::table('opportunity_stage_changes')
            ->joinSub(Sources::opportunities($f, $user)->select('opportunities.id', 'opportunities.organisation_id', 'opportunities.person_id'), 'o', 'o.id', '=', 'opportunity_stage_changes.opportunity_id')
            ->whereIn('opportunity_stage_changes.to_stage_id', $stages)
            ->groupByRaw('coalesce(o.organisation_id, o.person_id)')
            ->selectRaw('min(opportunity_stage_changes.changed_at) as first_at');

        return DB::query()->fromSub($firsts, 'f')->whereBetween('first_at', [$f->start(), $f->end()])->count();
    }
}
