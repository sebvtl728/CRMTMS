<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Public read service of the CRM for calendars (Connectors\Calendar): the
 * meetings and calls a user is responsible for, with a due date in
 * [$from, $to), within his visibility, on live fiches only; or one
 * action (find), for the calendars it is pushed to.
 */
final class CalendarEvents
{
    /** Events of one feed, at most. */
    public const LIMIT = 2000;

    /**
     * Rows and the fiche of their opportunity with its name (ficheName()),
     * loaded before ActivityRows::rights() so that its id-only loads do not
     * replace them.
     */
    private const WITH = [...ActivityRows::WITH, 'opportunity.organisation:id,name,deleted_at', 'opportunity.person:id,first_name,last_name,deleted_at'];

    /**
     * @return list<CalendarEvent>
     */
    public static function for(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $types = WeekPlanning::types();
        if ($types === []) {
            return [];
        }
        $format = fn (CarbonImmutable $at): string => $at->format('Y-m-d H:i:sP');

        $activities = TodoQuery::onLiveFiches(CrmVisibility::activities(Activity::query(), $user))
            ->where('responsible_user_id', $user->id)
            ->whereIn('type_id', array_keys($types))
            ->whereRaw('due_at >= ?::timestamptz and due_at < ?::timestamptz', [$format($from), $format($to)])
            ->with(self::WITH)
            ->orderBy('due_at')->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $rights = ActivityRows::rights($activities, $user);
        $events = [];
        foreach ($activities as $activity) {
            $events[] = self::event($activity, $types, $rights[(string) $activity->id]['fiche_visible']);
        }

        return $events;
    }

    /**
     * The calendar event of one action, as its responsible's calendars show
     * it, or null when it has none: deleted, not a meeting or call, without
     * due date or responsible, on a fiche in the trash, or out of the
     * responsible's visibility (or his account is gone).
     */
    public static function find(string $activityId): ?CalendarEvent
    {
        $types = WeekPlanning::types();
        $activity = $types === [] ? null : Activity::query()->whereKey($activityId)
            ->whereIn('type_id', array_keys($types))
            ->whereNotNull('due_at')->whereNotNull('responsible_user_id')
            ->first();
        $responsible = $activity === null ? null : User::query()->find($activity->responsible_user_id);
        if ($activity === null || $responsible === null) {
            return null;
        }

        $visible = TodoQuery::onLiveFiches(CrmVisibility::activities(Activity::query(), $responsible))
            ->whereKey($activity->id)->with(self::WITH)->first();

        if ($visible === null) {
            return null;
        }
        $rights = ActivityRows::rights(new Collection([$visible]), $responsible);

        return self::event($visible, $types, $rights[(string) $visible->id]['fiche_visible']);
    }

    /**
     * @param  array<string, string>  $types
     */
    private static function event(Activity $activity, array $types, bool $ficheVisible): CalendarEvent
    {
        $kind = $types[$activity->type_id] === WeekPlanning::CALL ? WeekPlanning::CALL : WeekPlanning::MEETING;

        return new CalendarEvent(
            id: $activity->id,
            kind: $kind,
            type: (string) $activity->type?->label,
            fiche: $activity->ficheName(),
            opportunity: $activity->opportunity?->title,
            result: $activity->result?->label,
            location: $activity->location,
            start: CarbonImmutable::instance($activity->due_at),
            durationMinutes: $activity->duration_minutes,
            url: self::url($activity, $ficheVisible),
            updatedAt: CarbonImmutable::instance($activity->updated_at ?? $activity->created_at ?? now()),
            responsibleUserId: $activity->responsible_user_id,
            purpose: $activity->purpose,
        );
    }

    /**
     * The page the responsible may open (ActivityRows::rights: its
     * opportunity, else its person, else its entreprise); for an action
     * handed to him on a fiche out of his scope, the action in « À faire ».
     */
    private static function url(Activity $activity, bool $ficheVisible): string
    {
        return match (true) {
            ! $ficheVisible => route('crm.todo', ['action' => $activity->id]),
            $activity->opportunity_id !== null => route('crm.opportunities.show', $activity->opportunity_id),
            $activity->person_id !== null => route('crm.people.show', $activity->person_id),
            default => route('crm.organisations.show', (string) $activity->organisation_id),
        };
    }
}
