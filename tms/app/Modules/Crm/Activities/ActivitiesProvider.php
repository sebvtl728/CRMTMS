<?php

namespace App\Modules\Crm\Activities;

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
 * « Historique » of an entreprise or a person: the actions on the fiche or
 * on one of its opportunities, at their done date (their due date while to
 * do), with type, status, result and report. Only the actions the viewer
 * sees (CrmVisibility), each with his rights on it (can_update,
 * can_delete); none without a signed-in viewer.
 */
final class ActivitiesProvider implements TimelineProvider
{
    public const TYPE = 'activity';

    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        $user = Auth::user();
        if ((! $subject instanceof Organisation && ! $subject instanceof Person) || ! $user instanceof User) {
            return collect();
        }

        $column = $subject instanceof Organisation ? 'organisation_id' : 'person_id';
        $query = Activity::query()->with(['type', 'result', 'responsible:id,name', 'opportunity:id,title,organisation_id,person_id,deleted_at'])
            ->where(fn ($q) => $q->where($column, $subject->getKey())
                ->orWhereIn('opportunity_id', Opportunity::withTrashed()->select('id')->where($column, $subject->getKey())));
        $now = CarbonImmutable::now();

        $activities = CrmVisibility::activities($query, $user)
            ->tap(fn ($q) => Timeline::after($q, $before, self::TYPE, 'happened_at'))
            ->orderByDesc('happened_at')->orderByDesc('id')->limit($limit)->get();
        $rights = ActivityRows::rights($activities, $user);

        return $activities->toBase()
            ->map(function (Activity $activity) use ($now, $rights): TimelineEntry {
                $status = $activity->status($now);
                assert($activity->happened_at !== null);

                return new TimelineEntry(
                    self::TYPE,
                    CarbonImmutable::instance($activity->happened_at),
                    $activity->subject,
                    $this->body($activity),
                    $activity->responsible?->name,
                    [
                        'id' => $activity->id,
                        'type' => $activity->type?->label,
                        'status' => $status,
                        'status_label' => Activity::STATUS_LABELS[$status],
                        'result' => $activity->result?->label,
                        'duration_minutes' => $activity->duration_minutes,
                        'opportunity_id' => $activity->opportunity_id,
                        'opportunity' => $activity->opportunity?->title,
                        ...$rights[(string) $activity->id],
                    ],
                );
            });
    }

    /**
     * The report, preceded by the place of a meeting.
     */
    private function body(Activity $activity): ?string
    {
        $parts = array_filter([
            $activity->location === null ? null : 'Lieu : '.$activity->location,
            $activity->report,
        ], fn (?string $p) => $p !== null && $p !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
