<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The week planning of a user: meetings (to do or done) and planned calls
 * (an « Appel » with a due date), Monday to Sunday on Paris calendar days,
 * placed at their due date (when they were planned), else when done.
 *
 * Meetings and calls are recognised by the stable code of their action
 * type (the label is the organisme's, the code never changes): the types
 * « Prise de rendez-vous » and « Rendez-vous physique », and « Appel ».
 * Always filtered by the visibility of the viewer, whoever's planning it
 * is; actions on a fiche in the trash are left out (as in « À faire »).
 */
final class WeekPlanning
{
    /** @var list<string> */
    public const MEETING_CODES = ['prise-de-rendez-vous', 'rendez-vous-physique'];

    public const CALL_CODE = 'appel';

    /** Rows of a week, at most. */
    public const LIMIT = 500;

    /**
     * Monday 0:00 (Paris) of an ISO week « 2026-W47 », or null when the
     * value is not a week that exists (« 2025-W53 », « 2026-W00 »).
     */
    public static function monday(mixed $week): ?CarbonImmutable
    {
        if (! is_string($week) || preg_match('/^(\d{4})-W(\d{2})$/', $week, $m) !== 1) {
            return null;
        }
        [$year, $number] = [(int) $m[1], (int) $m[2]];
        // 28 December always falls in the last ISO week of its year.
        $weeks = CarbonImmutable::create($year, 12, 28, 12, 0, 0, self::tz())?->isoWeek() ?? 52;
        if ($year < 2000 || $year > 2999 || $number < 1 || $number > $weeks) {
            return null;
        }

        return CarbonImmutable::now(self::tz())->setISODate($year, $number, 1)->startOfDay();
    }

    /** Monday 0:00 (Paris) of the week of $now. */
    public static function current(CarbonImmutable $now): CarbonImmutable
    {
        $today = $now->setTimezone(self::tz())->startOfDay();

        return $today->subDays($today->isoWeekday() - 1)->startOfDay();
    }

    /** « 2026-W47 ». */
    public static function label(CarbonImmutable $monday): string
    {
        return $monday->format('o-\WW');
    }

    /**
     * Seven days from $monday, each with its meetings and calls in time order.
     *
     * @return list<array{date: string, label: string, items: list<array<string, mixed>>}>
     */
    public static function days(User $viewer, User $owner, CarbonImmutable $monday): array
    {
        $types = self::types();
        $meetings = array_keys(array_filter($types, fn (string $code) => $code !== self::CALL_CODE));
        $nextMonday = $monday->addDays(7)->startOfDay();
        $format = fn (CarbonImmutable $at): string => $at->format('Y-m-d H:i:sP');

        $rows = $types === [] ? collect() : TodoQuery::onLiveFiches(CrmVisibility::activities(Activity::query(), $viewer))
            ->where('responsible_user_id', $owner->id)
            ->whereIn('type_id', array_keys($types))
            ->where(fn (Builder $q) => $q->whereIn('type_id', $meetings)->orWhereNotNull('due_at'))
            ->whereRaw('coalesce(due_at, done_at) >= ?::timestamptz and coalesce(due_at, done_at) < ?::timestamptz', [$format($monday), $format($nextMonday)])
            ->with(ActivityRows::WITH)
            ->orderByRaw('coalesce(due_at, done_at)')->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $byDay = [];
        foreach ($rows as $activity) {
            $at = CarbonImmutable::instance($activity->due_at ?? $activity->done_at)->setTimezone(self::tz());
            $row = ActivityRows::row($activity);
            unset($row['report']); // a list: the report stays on the fiche
            $byDay[$at->toDateString()][] = [
                ...$row,
                'kind' => ($types[$activity->type_id] ?? '') === self::CALL_CODE ? 'call' : 'meeting',
                'at' => $at->toIso8601String(),
                'time' => $at->format('H:i'),
            ];
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->addDays($i);
            $days[] = [
                'date' => $day->toDateString(),
                'label' => ucfirst($day->locale('fr')->isoFormat('dddd D MMMM')),
                'items' => $byDay[$day->toDateString()] ?? [],
            ];
        }

        return $days;
    }

    /**
     * Ids of the meeting and call types (deleted ones too: old actions keep
     * their type), with their code.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        /** @var array<string, string> */
        return ReferenceItem::withTrashed()->where('list', ReferenceList::ActionType)
            ->whereIn('code', [...self::MEETING_CODES, self::CALL_CODE])
            ->pluck('code', 'id')->all();
    }

    /**
     * Ids of the meeting types only.
     *
     * @return list<string>
     */
    public static function meetingTypes(): array
    {
        return array_keys(array_filter(self::types(), fn (string $code) => $code !== self::CALL_CODE));
    }

    private static function tz(): string
    {
        return (string) config('app.timezone');
    }
}
