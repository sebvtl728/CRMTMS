<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\ActivityRecorder;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;

/**
 * Actions another module plans in the CRM (lot 1e: the trainer's follow-up
 * and the reactivation calls, planned by a system task of Sessions). The
 * caller's rights are not checked: no user is behind a scheduled task.
 */
final class Activities
{
    /**
     * A call « à faire » on a live fiche (not trashed, not anonymised), of
     * the first action type marked meta.planning = call, for $userId; null
     * when nothing was planned. Through ActivityRecorder: journal, Outlook.
     *
     * @param  'organisation'|'person'  $ficheType
     */
    public static function planCall(string $ficheType, string $ficheId, string $userId, string $subject, CarbonImmutable $dueAt): ?string
    {
        $fiche = $ficheType === 'organisation'
            ? Organisation::query()->find($ficheId)
            : Person::query()->whereNull('anonymised_at')->find($ficheId);
        $type = ReferenceItem::query()->where('list', ReferenceList::ActionType)->where('meta->planning', 'call')->orderBy('position')->value('id');
        if ($fiche === null || $type === null) {
            return null;
        }

        [$activity] = app(ActivityRecorder::class)->create([
            'type_id' => $type,
            'subject' => mb_substr($subject, 0, 200),
            $ficheType === 'organisation' ? 'organisation_id' : 'person_id' => $fiche->id,
            'due_at' => $dueAt,
            'responsible_user_id' => $userId,
        ]);

        return $activity->id;
    }
}
