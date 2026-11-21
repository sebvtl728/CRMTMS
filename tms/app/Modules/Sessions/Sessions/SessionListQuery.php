<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The « Sessions » list and the monthly calendar: filter reading, filter
 * choices and the one query (view scope of sessions.sessions.view; the
 * trash only with sessions.sessions.delete). The state filter is the
 * effective state of the Paris day. The search covers the number, the title,
 * the place and the training. The state filter is the effective state of the Paris day (« En cours » / « Terminée » from the dates).
 *
 * @phpstan-type Filters array{q: string, status: ?string, kind: ?string, modality: ?string, das: ?string, training: ?string, responsible: ?string, from: ?string, to: ?string, trashed: bool, sort: string, dir: string}
 */
final class SessionListQuery
{
    /** Sort keys → columns. */
    public const SORTS = ['starts_on' => 'starts_on', 'number' => 'number'];

    /**
     * @param  array<array-key, mixed>  $input
     * @return Filters
     */
    public static function filters(array $input): array
    {
        $sort = self::oneOf($input['sort'] ?? null, array_keys(self::SORTS)) ?? 'starts_on';

        return [
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100) : '',
            'status' => self::oneOf($input['status'] ?? null, array_map(fn (SessionStatus $s) => $s->value, SessionStatus::cases())),
            'kind' => self::oneOf($input['kind'] ?? null, array_map(fn (SessionKind $k) => $k->value, SessionKind::cases())),
            'modality' => self::id($input['modality'] ?? null),
            'das' => self::id($input['das'] ?? null),
            'training' => self::id($input['training'] ?? null),
            'responsible' => self::id($input['responsible'] ?? null),
            'from' => self::date($input['from'] ?? null),
            'to' => self::date($input['to'] ?? null),
            'trashed' => filter_var($input['trashed'] ?? false, FILTER_VALIDATE_BOOL),
            'sort' => $sort,
            // Latest first by default; numbers in ascending order.
            'dir' => self::oneOf($input['dir'] ?? null, ['asc', 'desc']) ?? ($sort === 'starts_on' ? 'desc' : 'asc'),
        ];
    }

    /**
     * @param  Filters  $filters
     * @return Builder<TrainingSession>
     */
    public static function build(array $filters, User $user, CarbonImmutable $today): Builder
    {
        $query = SessionsVisibility::sessions(TrainingSession::query(), $user);
        if ($filters['trashed'] && $user->can(Permissions::SESSIONS_DELETE)) {
            $query->onlyTrashed();
        }

        $q = $filters['q'];
        $query
            ->when($q !== '', function (Builder $b) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $trainingIds = array_map(fn ($t) => $t->id, app(Trainings::class)->search($q, null, 100));
                $b->where(fn (Builder $w) => $w
                    ->where('training_sessions.number', 'ilike', $like)
                    ->orWhere('training_sessions.title', 'ilike', $like)
                    ->orWhere('training_sessions.location_text', 'ilike', $like)
                    ->when($trainingIds !== [], fn (Builder $t) => $t->orWhereIn('training_sessions.training_id', $trainingIds)));
            })
            ->when($filters['status'], fn (Builder $b, string $status) => self::status($b, SessionStatus::from($status), $today))
            ->when($filters['kind'], fn (Builder $b, string $kind) => $b->where('training_sessions.kind', $kind))
            ->when($filters['modality'], fn (Builder $b, string $id) => $b->where('training_sessions.modality_id', $id))
            ->when($filters['das'], fn (Builder $b, string $id) => $b->where('training_sessions.das_id', $id))
            ->when($filters['training'], fn (Builder $b, string $id) => $b->where('training_sessions.training_id', $id))
            ->when($filters['responsible'], fn (Builder $b, string $id) => $b->where('training_sessions.responsible_user_id', $id))
            // Sessions overlapping the period.
            ->when($filters['from'], fn (Builder $b, string $from) => $b->where('training_sessions.ends_on', '>=', $from))
            ->when($filters['to'], fn (Builder $b, string $to) => $b->where('training_sessions.starts_on', '<=', $to));

        return $query->orderBy('training_sessions.'.self::SORTS[$filters['sort']], $filters['dir'])
            ->orderBy('training_sessions.number', $filters['dir']);
    }

    /**
     * Sessions visible to $user overlapping the month starting on $first, not
     * trashed, by start date (the calendar).
     *
     * @param  array{kind?: ?string, modality?: ?string, das?: ?string}  $filters
     * @return Builder<TrainingSession>
     */
    public static function month(CarbonImmutable $first, User $user, array $filters = []): Builder
    {
        return SessionsVisibility::sessions(TrainingSession::query(), $user)
            ->where('training_sessions.starts_on', '<=', $first->endOfMonth()->toDateString())
            ->where('training_sessions.ends_on', '>=', $first->toDateString())
            ->when($filters['kind'] ?? null, fn (Builder $b, string $kind) => $b->where('training_sessions.kind', $kind))
            ->when($filters['modality'] ?? null, fn (Builder $b, string $id) => $b->where('training_sessions.modality_id', $id))
            ->when($filters['das'] ?? null, fn (Builder $b, string $id) => $b->where('training_sessions.das_id', $id))
            ->orderBy('training_sessions.starts_on')->orderBy('training_sessions.number');
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'status' => SessionStatus::options(),
            'kind' => SessionKind::options(),
            'modality' => self::reference(ReferenceList::TrainingModality),
            'das' => self::reference(ReferenceList::Das),
            'training' => array_map(fn ($t) => ['value' => $t->id, 'label' => $t->title], app(Trainings::class)->options()),
            'responsible' => self::responsible(),
            'sort' => [
                ['value' => 'starts_on', 'label' => 'Date de début'],
                ['value' => 'number', 'label' => 'Numéro'],
            ],
        ];
    }

    /**
     * Active items of $list, plus $keep even when inactive.
     *
     * @param  list<string>  $keep
     * @return list<array{value: string, label: string}>
     */
    public static function reference(ReferenceList $list, array $keep = []): array
    {
        $keep = array_values(array_filter($keep));

        return ReferenceItem::query()->where('list', $list)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep))
            ->orderBy('position')->orderBy('label')->get()
            ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->is_active ? $i->label : $i->label.' (inactif)'])
            ->values()->all();
    }

    /**
     * Active users, plus $keep when deactivated.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function responsible(?string $keep = null): array
    {
        return User::query()->where(fn ($q) => $q->whereNull('deactivated_at')->when($keep, fn ($q2, $id) => $q2->orWhere('id', $id)))
            ->orderBy('name')->get(['id', 'name', 'deactivated_at'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->isActive() ? $u->name : $u->name.' (désactivé)'])
            ->values()->all();
    }

    /**
     * @param  Builder<TrainingSession>  $query
     */
    private static function status(Builder $query, SessionStatus $status, CarbonImmutable $today): void
    {
        $day = $today->toDateString();
        $open = [SessionStatus::Planned->value, SessionStatus::Confirmed->value];

        match ($status) {
            SessionStatus::Planned, SessionStatus::Confirmed => $query
                ->where('training_sessions.status', $status->value)->where('training_sessions.starts_on', '>', $day),
            SessionStatus::Running => $query->where(fn (Builder $q) => $q
                ->where('training_sessions.status', SessionStatus::Running->value)
                ->orWhere(fn (Builder $o) => $o->whereIn('training_sessions.status', $open)
                    ->where('training_sessions.starts_on', '<=', $day)->where('training_sessions.ends_on', '>=', $day)
                    // Its last day with its last month closed: already done.
                    ->whereNot(fn (Builder $last) => $last->where('training_sessions.ends_on', $day)->whereRaw(TrainingSession::LAST_MONTH_CLOSED_SQL)))),
            SessionStatus::Done => $query->where(fn (Builder $q) => $q
                ->where('training_sessions.status', SessionStatus::Done->value)
                ->orWhere(fn (Builder $o) => $o->whereIn('training_sessions.status', $open)->where(fn (Builder $end) => $end
                    ->where('training_sessions.ends_on', '<', $day)
                    ->orWhere(fn (Builder $last) => $last->where('training_sessions.ends_on', $day)->whereRaw(TrainingSession::LAST_MONTH_CLOSED_SQL))))),
            SessionStatus::Cancelled => $query->where('training_sessions.status', SessionStatus::Cancelled->value),
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
