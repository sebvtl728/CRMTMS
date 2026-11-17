<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * « Sessions en colonnes » (spec § 4.3): one column per state shown on the
 * Paris day (Planifiée, Confirmée, En cours, Terminée, and Annulée when
 * asked), each read through SessionListQuery (same filters, same scope; the
 * state filter is the column's). « Terminée » keeps the sessions ended
 * within the last 90 days. At most 50 cards per column, by start date
 * (most recent end first when done), with the count of all of them. A card
 * can move (Planifiée ↔ Confirmée) when the user may update the session.
 * The cards of all the columns are read together: the number of queries
 * does not depend on the number of sessions.
 *
 * @phpstan-import-type Filters from SessionListQuery
 */
final class SessionBoard
{
    public const LIMIT = 50;

    /** « Terminée »: ended less than this many days ago. */
    public const DONE_DAYS = 90;

    /**
     * @param  Filters  $filters
     * @return list<array{status: string, label: string, total: int, cards: list<array<string, mixed>>}>
     */
    public static function build(array $filters, User $user, CarbonImmutable $today, bool $withCancelled): array
    {
        $statuses = [SessionStatus::Planned, SessionStatus::Confirmed, SessionStatus::Running, SessionStatus::Done,
            ...($withCancelled ? [SessionStatus::Cancelled] : [])];

        $columns = [];
        /** @var Collection<int, TrainingSession> $all */
        $all = new Collection;
        foreach ($statuses as $status) {
            $query = SessionListQuery::build([...$filters, 'status' => $status->value], $user, $today)->reorder();
            if ($status === SessionStatus::Done) {
                $query->where('training_sessions.ends_on', '>', $today->subDays(self::DONE_DAYS)->toDateString());
            }
            $total = (clone $query)->count();
            $sessions = ($status === SessionStatus::Done
                ? $query->orderByDesc('training_sessions.ends_on')->orderByDesc('training_sessions.number')
                : $query->orderBy('training_sessions.starts_on')->orderBy('training_sessions.number'))
                ->limit(self::LIMIT)->get();
            $columns[] = ['status' => $status, 'total' => $total, 'ids' => $sessions->modelKeys()];
            $all = $all->concat($sessions);
        }

        $rows = collect(SessionRows::rows($all, $today, $user))->keyBy('id');
        $movable = self::movable($all, $user, $today);

        return array_map(fn (array $column) => [
            'status' => $column['status']->value,
            'label' => $column['status']->label(),
            'total' => $column['total'],
            'cards' => array_map(fn (string $id) => [...$rows[$id], 'can_move' => isset($movable[$id])], $column['ids']),
        ], $columns);
    }

    /**
     * Sessions shown planned or confirmed, out of the trash, that the user
     * may update (one query for the scope).
     *
     * @param  Collection<int, TrainingSession>  $sessions
     * @return array<string, true>
     */
    private static function movable(Collection $sessions, User $user, CarbonImmutable $today): array
    {
        $open = $sessions->filter(fn (TrainingSession $s) => ! $s->trashed()
            && in_array($s->effectiveStatus($today), SessionStatus::SETTABLE, true))->modelKeys();
        if ($open === [] || ! $user->can(Permissions::SESSIONS_UPDATE)) {
            return [];
        }

        return array_fill_keys(
            SessionsVisibility::sessions(TrainingSession::query()->whereKey($open), $user, Permissions::SESSIONS_UPDATE)->pluck('id')->all(),
            true,
        );
    }
}
