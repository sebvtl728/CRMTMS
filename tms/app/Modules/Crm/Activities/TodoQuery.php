<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The « À faire » list: actions to do (not done, with a due date, not
 * trashed, on a fiche still alive), sorted by due date, in three sections
 * cut on Paris calendar days: overdue (before today), today, and the rest
 * of the week up to Sunday evening. Always filtered by the visibility of
 * the viewer, whoever the responsible is.
 *
 * The menu badge (overdue + today) is cached 60 seconds per user, under a
 * key prefixed by the tenant id; Activity drops it when an action changes.
 */
final class TodoQuery
{
    public const BADGE_TTL = 60;

    /** Rows per section on the page. */
    public const LIMIT = 200;

    /**
     * @return array{overdue: Collection<int, Activity>, today: Collection<int, Activity>, week: Collection<int, Activity>}
     */
    public static function for(User $user, CarbonImmutable $now, bool $team = false): array
    {
        [$today, $tomorrow, $nextWeek] = self::bounds($now);

        $section = fn (?string $from, ?string $to): Collection => self::base($user, $team)
            ->when($from !== null, fn (Builder $q) => $q->where('due_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('due_at', '<', $to))
            ->with(ActivityRows::WITH)
            ->orderBy('due_at')->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return [
            'overdue' => $section(null, $today),
            'today' => $section($today, $tomorrow),
            'week' => $section($tomorrow, $nextWeek),
        ];
    }

    /**
     * Overdue + today of the user's own actions.
     */
    public static function count(User $user, CarbonImmutable $now): int
    {
        return self::base($user, false)->where('due_at', '<', self::bounds($now)[1])->count();
    }

    /**
     * The menu badge, cached 60 s per user.
     */
    public static function badge(User $user): int
    {
        return Cache::remember(self::key($user->id), self::BADGE_TTL, fn () => self::count($user, CarbonImmutable::now()));
    }

    /**
     * Drops the cached badge of a user (an action of theirs changed).
     */
    public static function forget(?string $userId): void
    {
        if ($userId !== null) {
            Cache::forget(self::key($userId));
        }
    }

    /**
     * Prefixed by the tenant id: the number is organisme data.
     */
    private static function key(string $userId): string
    {
        return app(TenantContext::class)->id().':todo-badge:'.$userId;
    }

    /**
     * Actions to do the viewer may see, theirs or (team) all of them.
     *
     * @return Builder<Activity>
     */
    private static function base(User $user, bool $team): Builder
    {
        $query = CrmVisibility::activities(Activity::query(), $user)
            ->whereNull('done_at')
            ->whereNotNull('due_at')
            ->when(! $team, fn (Builder $q) => $q->where('responsible_user_id', $user->id));

        // Nothing to do on a fiche in the trash.
        foreach (['organisation_id' => 'organisations', 'person_id' => 'people', 'opportunity_id' => 'opportunities'] as $column => $table) {
            $query->whereRaw("(activities.{$column} is null or exists (select 1 from {$table} t where t.id = activities.{$column} and t.deleted_at is null))");
        }

        return $query;
    }

    /**
     * Start of today, of tomorrow and of next Monday in Paris, as strings
     * with their offset (a bare timestamp would lose the zone).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function bounds(CarbonImmutable $now): array
    {
        $today = $now->setTimezone((string) config('app.timezone'))->startOfDay();
        $nextMonday = $today->addDays(8 - $today->isoWeekday())->startOfDay();
        $format = fn (CarbonImmutable $at): string => $at->format('Y-m-d H:i:sP');

        return [$format($today), $format($today->addDay()->startOfDay()), $format($nextMonday)];
    }
}
