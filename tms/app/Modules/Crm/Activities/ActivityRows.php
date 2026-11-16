<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Collection;

/**
 * An action as shown on screen (fiche of an opportunity, « Fait » dialog).
 */
final class ActivityRows
{
    /** Relations row() reads. */
    public const WITH = ['type', 'result', 'responsible:id,name', 'organisation:id,name,deleted_at', 'person:id,first_name,last_name,deleted_at', 'opportunity:id,title,organisation_id,person_id,deleted_at'];

    /**
     * @return array<string, mixed>
     */
    public static function row(Activity $activity): array
    {
        $status = $activity->status();

        return [
            'id' => $activity->id,
            'type' => $activity->type?->label,
            'type_id' => $activity->type_id,
            'subject' => $activity->subject,
            'purpose' => $activity->purpose,
            'report' => $activity->report,
            'next_steps' => $activity->next_steps,
            'location' => $activity->location,
            'due_at' => $activity->due_at?->toIso8601String(),
            'done_at' => $activity->done_at?->toIso8601String(),
            'duration_minutes' => $activity->duration_minutes,
            'result' => $activity->result?->label,
            'result_id' => $activity->result_id,
            'responsible' => $activity->responsible?->name,
            'responsible_user_id' => $activity->responsible_user_id,
            'status' => $status,
            'status_label' => Activity::STATUS_LABELS[$status],
            'organisation' => $activity->organisation === null ? null : ['id' => $activity->organisation->id, 'name' => $activity->organisation->name],
            'person' => $activity->person === null ? null : ['id' => $activity->person->id, 'name' => $activity->person->fullName()],
            'opportunity' => $activity->opportunity === null ? null : ['id' => $activity->opportunity->id, 'name' => $activity->opportunity->title],
        ];
    }

    /**
     * Rows with the viewer's rights on each action (the policy's rule, in
     * two queries): « Fait », « Reporter » with can_update, the bin with can_delete.
     *
     * @param  Collection<int, Activity>  $activities
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $activities, User $user): array
    {
        $rights = self::rights($activities, $user);

        return $activities->map(fn (Activity $a) => [...self::row($a), ...$rights[(string) $a->id]])->values()->all();
    }

    /**
     * Same rule as ActivityPolicy::update / delete: in the scope of the
     * right, never on a trashed fiche. fiche_visible: the viewer may open
     * the page the action leads to (its opportunity, else its person, else
     * its entreprise); an action handed to him may hang on a fiche he does
     * not see (« Confier à… »): its name is then shown without a link.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<string, array{can_update: bool, can_delete: bool, fiche_visible: bool}>
     */
    public static function rights(Collection $activities, User $user): array
    {
        $activities->loadMissing(['organisation:id,deleted_at', 'person:id,deleted_at', 'opportunity.organisation:id,deleted_at', 'opportunity.person:id,deleted_at']);
        $update = CrmVisibility::allowedIds($user, Permissions::ACTIVITIES_UPDATE, $activities);
        $delete = CrmVisibility::allowedIds($user, Permissions::ACTIVITIES_DELETE, $activities);
        $visible = fn (string $permission, string $relation): array => CrmVisibility::allowedIds(
            $user, $permission, new Collection($activities->map(fn (Activity $a) => $a->getRelation($relation))->filter()->unique('id')->values()->all()));
        $opportunities = $visible(Permissions::OPPORTUNITIES_VIEW, 'opportunity');
        $people = $visible(Permissions::FICHES_VIEW, 'person');
        $organisations = $visible(Permissions::FICHES_VIEW, 'organisation');

        $rights = [];
        foreach ($activities as $activity) {
            $id = (string) $activity->id;
            $live = ! $activity->ficheTrashed();
            $fiche = match (true) {
                $activity->opportunity_id !== null => isset($opportunities[$activity->opportunity_id]),
                $activity->person_id !== null => isset($people[$activity->person_id]),
                $activity->organisation_id !== null => isset($organisations[$activity->organisation_id]),
                default => false,
            };
            $rights[$id] = ['can_update' => $live && isset($update[$id]), 'can_delete' => $live && isset($delete[$id]), 'fiche_visible' => $live && $fiche];
        }

        return $rights;
    }
}
