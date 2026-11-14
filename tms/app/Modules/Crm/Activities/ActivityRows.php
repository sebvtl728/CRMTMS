<?php

namespace App\Modules\Crm\Activities;

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
            'report' => $activity->report,
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
}
