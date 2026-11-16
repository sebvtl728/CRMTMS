<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Timeline\TimelineCursor;
use App\Modules\Crm\Timeline\TimelineEntry;
use App\Modules\Crm\Timeline\TimelineProvider;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * « Action confiée à X par Y » in the « Historique » of an entreprise or a
 * person: the crm.activity_assigned journal entries (user ids only) of the
 * actions on the fiche or on one of its opportunities that the viewer sees.
 */
final class ActivityAssignmentsProvider implements TimelineProvider
{
    public const TYPE = 'activity_assigned';

    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        $user = Auth::user();
        if ((! $subject instanceof Organisation && ! $subject instanceof Person) || ! $user instanceof User) {
            return collect();
        }

        $column = $subject instanceof Organisation ? 'organisation_id' : 'person_id';
        $activities = CrmVisibility::activities(Activity::withTrashed(), $user)
            ->where(fn ($q) => $q->where($column, $subject->getKey())
                ->orWhereIn('opportunity_id', Opportunity::withTrashed()->select('id')->where($column, $subject->getKey())))
            ->selectRaw('activities.id::text');

        $logs = AuditLog::query()->with('user:id,name')
            ->where('event', 'crm.activity_assigned')
            ->where('auditable_type', (new Activity)->getMorphClass())
            ->whereIn('auditable_id', $activities->toBase())
            ->tap(fn ($q) => Timeline::after($q, $before, self::TYPE))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        $ids = $logs->map(fn (AuditLog $log) => $log->new_values['responsible_user_id'] ?? null)->filter(fn ($id) => is_string($id))->unique()->values();
        $names = User::query()->whereKey($ids->all())->pluck('name', 'id');

        return $logs->toBase()->map(function (AuditLog $log) use ($names): TimelineEntry {
            $to = $log->new_values['responsible_user_id'] ?? null;
            $toName = is_string($to) ? ($names[$to] ?? 'un utilisateur') : 'un utilisateur';
            $byName = $log->user->name ?? 'un utilisateur';

            return new TimelineEntry(
                self::TYPE,
                CarbonImmutable::instance($log->created_at),
                "Action confiée à {$toName} par {$byName}",
                null,
                $log->user?->name,
                ['id' => $log->id, 'activity_id' => $log->auditable_id],
            );
        });
    }
}
