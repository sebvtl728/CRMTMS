<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Collection;

/**
 * Rows of an opportunity list (list page, « Opportunités » tab of a fiche).
 */
final class OpportunityRows
{
    /** @var list<string> */
    public const WITH = ['organisation:id,name,deleted_at', 'person:id,first_name,last_name,deleted_at', 'das', 'stage', 'priority', 'responsible:id,name'];

    /**
     * @param  array{type: string|null, subject: string|null, due_at: string, overdue: bool}|null  $nextAction
     * @return array<string, mixed>
     */
    public static function row(Opportunity $o, ?array $nextAction = null): array
    {
        return [
            'id' => $o->id,
            'title' => $o->title,
            'target' => $o->isIndividual() ? 'individual' : 'organisation',
            'account' => $o->isIndividual()
                ? ['type' => 'person', 'id' => $o->person_id, 'name' => $o->person?->fullName()]
                : ['type' => 'organisation', 'id' => $o->organisation_id, 'name' => $o->organisation?->name],
            'das' => $o->das?->label,
            'stage' => ['id' => $o->stage_id, 'label' => $o->stage?->label, 'outcome' => $o->stage === null ? null : OpportunityStage::outcome($o->stage)],
            'probability' => $o->probability,
            'amount' => $o->amount,
            'weighted_amount' => $o->weighted_amount,
            'expected_close_on' => $o->expected_close_on?->toDateString(),
            'priority' => $o->priority?->label,
            'responsible' => $o->responsible?->name,
            'created_at' => $o->created_at?->toIso8601String(),
            'next_action' => $nextAction,
        ];
    }

    /**
     * The next action of each opportunity: the earliest dated action still to
     * do that the user may see (none without « Consulter les actions »), in
     * one query.
     *
     * @param  iterable<Opportunity>  $opportunities
     * @return array<string, array{type: string|null, subject: string|null, due_at: string, overdue: bool}>
     */
    public static function nextActions(iterable $opportunities, User $user): array
    {
        $ids = Collection::make($opportunities)->map(fn (Opportunity $o) => (string) $o->id)->values()->all();
        if ($ids === []) {
            return [];
        }

        return CrmVisibility::activities(Activity::query(), $user)
            ->selectRaw('distinct on (activities.opportunity_id) activities.*')
            ->whereIn('activities.opportunity_id', $ids)
            ->whereNull('activities.done_at')
            ->whereNotNull('activities.due_at')
            ->orderBy('activities.opportunity_id')
            ->orderBy('activities.due_at')
            ->orderBy('activities.id')
            ->with('type')
            ->get()
            ->mapWithKeys(fn (Activity $a) => [(string) $a->opportunity_id => [
                'type' => $a->type?->label,
                'subject' => $a->subject,
                'due_at' => $a->due_at?->toIso8601String() ?? '',
                'overdue' => $a->status() === 'overdue',
            ]])->all();
    }

    /**
     * Rows with their next action.
     *
     * @param  Collection<int, Opportunity>  $opportunities
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $opportunities, User $user): array
    {
        $next = self::nextActions($opportunities, $user);

        return $opportunities->map(fn (Opportunity $o) => self::row($o, $next[(string) $o->id] ?? null))->values()->all();
    }

    /**
     * The opportunities of a fiche the user may see, open ones first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forFiche(Organisation|Person $fiche, User $user): array
    {
        return CrmVisibility::opportunities(Opportunity::query(), $user)
            ->where($fiche instanceof Organisation ? 'organisation_id' : 'person_id', $fiche->getKey())
            ->with(self::WITH)
            ->orderByRaw('won_on is not null or lost_on is not null')
            ->orderByRaw('expected_close_on asc nulls last')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->pipe(fn (Collection $opportunities) => self::rows($opportunities, $user));
    }
}
