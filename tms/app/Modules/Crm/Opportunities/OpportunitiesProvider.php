<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Timeline\TimelineCursor;
use App\Modules\Crm\Timeline\TimelineEntry;
use App\Modules\Crm\Timeline\TimelineProvider;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * « Historique » of an entreprise or a particulier: the stage history of the
 * opportunities it holds (created, « Étude → Proposition », won, lost with
 * the reason given then). Deleted opportunities are left out; a Responsable
 * DAS only sees those of his DAS; none without a signed-in viewer. The
 * loss comment is never shown (it may describe the person).
 */
final class OpportunitiesProvider implements TimelineProvider
{
    public const TYPE = 'opportunity';

    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        $user = Auth::user();
        if ((! $subject instanceof Organisation && ! $subject instanceof Person) || ! $user instanceof User) {
            return collect();
        }

        $opportunities = CrmVisibility::opportunities(
            Opportunity::query()->where($subject instanceof Organisation ? 'organisation_id' : 'person_id', $subject->getKey()),
            $user,
        );

        return StageChange::query()->with(['opportunity:id,title', 'fromStage', 'toStage', 'lossReason', 'changer:id,name'])
            ->whereIn('opportunity_id', $opportunities->select('opportunities.id'))
            ->tap(fn ($q) => Timeline::after($q, $before, self::TYPE, 'changed_at'))
            ->orderByDesc('changed_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (StageChange $change) => new TimelineEntry(
                self::TYPE,
                $change->changed_at,
                $this->title($change),
                $change->toStage !== null && OpportunityStage::outcome($change->toStage) === 'lost' && $change->lossReason !== null
                    ? 'Motif : '.$change->lossReason->label
                    : null,
                $change->changer?->name,
                ['id' => $change->id, 'opportunity_id' => $change->opportunity_id, 'event' => $this->event($change)],
            ));
    }

    private function event(StageChange $change): string
    {
        if ($change->from_stage_id === null) {
            return 'created';
        }

        return ($change->toStage === null ? null : OpportunityStage::outcome($change->toStage)) ?? 'moved';
    }

    private function title(StageChange $change): string
    {
        $title = '« '.($change->opportunity->title ?? '-').' »';

        return match ($this->event($change)) {
            'created' => "Opportunité créée : {$title}",
            'won' => "Opportunité gagnée : {$title}",
            'lost' => "Opportunité perdue : {$title}",
            default => "Opportunité {$title} : ".($change->fromStage->label ?? '-').' → '.($change->toStage->label ?? '-'),
        };
    }
}
