<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Compte rendu de RDV »: in one transaction, the done action (the planned
 * meeting completed, or a new action on the fiche), the stage of the
 * opportunity and the next follow-up. Any refusal rolls everything back.
 * Completion and follow-up are ActivityRecorder's, the stage rules
 * OpportunityStage's.
 */
final class QuickReport
{
    public function __construct(
        private readonly ActivityRecorder $recorder,
        private readonly OpportunityStage $stages,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated (QuickReportRequest::values)
     * @param  array{type_id: string, due_at: CarbonImmutable}|null  $next
     * @param  array{opportunity: Opportunity, stage: ReferenceItem, loss_reason_id: ?string, loss_comment: ?string}|null  $move
     * @return array{0: Activity, 1: Activity|null} the done action and its follow-up
     */
    public function record(?Activity $meeting, array $attributes, ?array $next, ?array $move): array
    {
        return DB::transaction(function () use ($meeting, $attributes, $next, $move): array {
            if ($meeting !== null) {
                // Two reports of the same meeting at once: the second one is refused.
                $locked = Activity::query()->lockForUpdate()->find($meeting->id);
                if ($locked === null || $locked->done_at !== null) {
                    throw ValidationException::withMessages(['activity_id' => 'Ce rendez-vous a déjà son compte rendu.']);
                }
                $followUp = $this->recorder->complete($locked, $attributes, $next);
                $done = $locked;
            } else {
                [$done, $followUp] = $this->recorder->create($attributes, $next);
            }

            if ($move !== null) {
                $this->stages->move($move['opportunity'], $move['stage'], $move['loss_reason_id'], $move['loss_comment'], null);
                // The next step is about the opportunity that moved, even when the meeting was not.
                if ($followUp !== null && $followUp->opportunity_id === null) {
                    $followUp->forceFill(['opportunity_id' => $move['opportunity']->id])->save();
                }
            }

            return [$done, $followUp];
        });
    }

    /**
     * The user's meetings to do today (Paris), visible, on a fiche alive.
     *
     * @return Collection<int, Activity>
     */
    public static function meetingsOfToday(User $user, CarbonImmutable $now): Collection
    {
        $tz = (string) config('app.timezone');
        $today = $now->setTimezone($tz)->startOfDay();
        $format = fn (CarbonImmutable $at): string => $at->format('Y-m-d H:i:sP');

        return TodoQuery::onLiveFiches(CrmVisibility::activities(Activity::query(), $user))
            ->where('responsible_user_id', $user->id)
            ->whereIn('type_id', WeekPlanning::meetingTypes())
            ->whereNull('done_at')
            ->where('due_at', '>=', $format($today))
            ->where('due_at', '<', $format($today->addDay()->startOfDay()))
            ->with(ActivityRows::WITH)
            ->orderBy('due_at')->orderBy('id')
            ->limit(50)
            ->get();
    }

    /**
     * Opportunities a report may move (the rule of QuickReportRequest): the
     * meeting's own, else those of its entreprise and of its person
     * (particulier), that the user sees and may update.
     *
     * @return list<array{id: string, name: string, stage_id: string}>
     */
    public static function opportunitiesFor(User $user, ?string $opportunityId, ?string $organisationId, ?string $personId): array
    {
        $query = CrmVisibility::opportunities(Opportunity::query(), $user)->with(['organisation', 'person']);
        if ($opportunityId !== null) {
            $query->whereKey($opportunityId);
        } elseif ($organisationId !== null || $personId !== null) {
            $query->where(fn ($q) => $q
                ->when($organisationId !== null, fn ($w) => $w->orWhere('organisation_id', $organisationId))
                ->when($personId !== null, fn ($w) => $w->orWhere('person_id', $personId)));
        } else {
            return [];
        }

        return $query->orderByDesc('updated_at')->orderBy('id')->limit(20)->get()
            ->filter(fn (Opportunity $o) => $user->can('update', $o))
            ->map(fn (Opportunity $o) => ['id' => $o->id, 'name' => $o->title, 'stage_id' => $o->stage_id])
            ->values()->all();
    }
}
