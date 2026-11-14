<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;

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
            ->with(ActivityRows::WITH)
            ->orderBy('due_at')->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $events = [];
        foreach ($activities as $activity) {
            $events[] = self::event($activity, $types);
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
            ->whereKey($activity->id)->with(ActivityRows::WITH)->first();

        return $visible === null ? null : self::event($visible, $types);
    }

    /**
     * @param  array<string, string>  $types
     */
    private static function event(Activity $activity, array $types): CalendarEvent
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
            url: self::url($activity),
            updatedAt: CarbonImmutable::instance($activity->updated_at ?? $activity->created_at ?? now()),
            responsibleUserId: $activity->responsible_user_id,
        );
    }

    private static function url(Activity $activity): string
    {
        return match (true) {
            $activity->person_id !== null => route('crm.people.show', $activity->person_id),
            $activity->organisation_id !== null => route('crm.organisations.show', $activity->organisation_id),
            default => route('crm.opportunities.show', (string) $activity->opportunity_id),
        };
    }
}
