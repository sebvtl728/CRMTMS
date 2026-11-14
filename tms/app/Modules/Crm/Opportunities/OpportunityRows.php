<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Rows of an opportunity list (list page, « Opportunités » tab of a fiche).
 */
final class OpportunityRows
{
    /** @var list<string> */
    public const WITH = ['organisation:id,name,deleted_at', 'person:id,first_name,last_name,deleted_at', 'das', 'stage', 'priority', 'responsible:id,name'];

    /**
     * @return array<string, mixed>
     */
    public static function row(Opportunity $o): array
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
        ];
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
            ->map(fn (Opportunity $o) => self::row($o))->values()->all();
    }
}
