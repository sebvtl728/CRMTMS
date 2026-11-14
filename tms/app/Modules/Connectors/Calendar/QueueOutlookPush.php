<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Crm\Activities\Events\ActivityChanged;

/**
 * Listens to the CRM: an action changed, its Outlook copies follow
 * (PushActivityToOutlook, queued after commit). Nothing is queued when the
 * organisme has no usable Microsoft 365 configuration, or when neither the
 * responsible (new or previous) has an active link nor an Outlook event of
 * this action exists.
 */
final class QueueOutlookPush
{
    public function handle(ActivityChanged $event): void
    {
        if (Microsoft365Connector::activeConfig() === null) {
            return;
        }

        $users = array_values(array_filter([$event->responsibleUserId, $event->previousResponsibleUserId]));
        $concerned = ($users !== [] && CalendarLink::query()->whereIn('user_id', $users)->where('status', CalendarLink::ACTIVE)->exists())
            || CalendarLinkEvent::query()->where('activity_id', $event->activityId)->exists();

        if ($concerned) {
            PushActivityToOutlook::dispatch($event->activityId)->afterCommit();
        }
    }
}
