<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Crm\Activities\CalendarEvent;

/**
 * The Microsoft Graph event of a CRM meeting or call: type and fiche,
 * opportunity, place and the link to the fiche. Never the report nor the
 * subject (CalendarEvent carries neither). Local Paris times with their
 * time zone; the end is the start plus the exact duration (right across a
 * DST change).
 */
final class OutlookEvent
{
    public const TIMEZONE = 'Europe/Paris';

    /** Without a duration: a meeting lasts an hour, a call a quarter of an hour (as the iCal feed). */
    public const DEFAULT_MINUTES = IcalFeed::DEFAULT_MINUTES;

    /**
     * The body of a creation (with its $transactionId: a replay does not
     * duplicate it), or of an update (null: the place is always sent, so
     * that a removed place is cleared).
     *
     * @return array<string, mixed>
     */
    public static function payload(CalendarEvent $event, ?string $transactionId = null): array
    {
        $start = $event->start->utc();
        $end = $start->addMinutes($event->durationMinutes ?? self::DEFAULT_MINUTES[$event->kind]);
        $local = fn ($at): array => ['dateTime' => $at->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i:s'), 'timeZone' => self::TIMEZONE];

        $lines = array_filter([
            $event->fiche !== '' ? 'Fiche : '.$event->fiche : null,
            $event->opportunity !== null ? 'Opportunité : '.$event->opportunity : null,
            $event->location !== null && trim($event->location) !== '' ? 'Lieu : '.$event->location : null,
            'Ouvrir dans le CRM : '.$event->url,
        ]);

        $payload = [
            'subject' => trim($event->type.($event->fiche !== '' ? ' – '.$event->fiche : '')),
            'body' => ['contentType' => 'text', 'content' => implode("\n", $lines)],
            'start' => $local($start),
            'end' => $local($end),
            'showAs' => 'busy',
        ];
        $place = $event->location !== null && trim($event->location) !== '' ? $event->location : null;
        if ($transactionId !== null) {
            $payload['transactionId'] = $transactionId;
            if ($place !== null) {
                $payload['location'] = ['displayName' => $place];
            }
        } else {
            $payload['location'] = ['displayName' => $place ?? ''];
        }

        return $payload;
    }
}
