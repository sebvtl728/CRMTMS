<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Crm\Activities\CalendarEvent;
use Carbon\CarbonInterface;

/**
 * The Microsoft Graph event of a CRM meeting or call: type and fiche,
 * purpose (« Motif »), opportunity, place and the link to the fiche. Never
 * the report, the next steps nor the subject (CalendarEvent carries none);
 * or of an item of another source (fromItem: OutlookSources). Local Paris
 * times with their time zone; the end of an action is its start plus the
 * exact duration (right across a DST change).
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

        $lines = array_filter([
            $event->fiche !== '' ? 'Fiche : '.$event->fiche : null,
            $event->purpose !== null && trim($event->purpose) !== '' ? 'Motif : '.$event->purpose : null,
            $event->opportunity !== null ? 'Opportunité : '.$event->opportunity : null,
            $event->location !== null && trim($event->location) !== '' ? 'Lieu : '.$event->location : null,
            'Ouvrir dans le CRM : '.$event->url,
        ]);

        $payload = [
            'subject' => trim($event->type.($event->fiche !== '' ? ' – '.$event->fiche : '')),
            'body' => ['contentType' => 'text', 'content' => implode("\n", $lines)],
            'start' => self::local($start),
            'end' => self::local($end),
            'showAs' => 'busy',
        ];

        return self::withPlace($payload, $event->location, $transactionId);
    }

    /**
     * The body of the event of a source's item (OutlookSources): its title,
     * exact start and end, place and text, as the source wrote them.
     *
     * @return array<string, mixed>
     */
    public static function fromItem(OutlookItem $item, ?string $transactionId = null): array
    {
        return self::withPlace([
            'subject' => $item->subject,
            'body' => ['contentType' => 'text', 'content' => $item->body],
            'start' => self::local($item->start),
            'end' => self::local($item->end),
            'showAs' => 'busy',
        ], $item->location, $transactionId);
    }

    /**
     * @return array{dateTime: string, timeZone: string}
     */
    private static function local(CarbonInterface $at): array
    {
        return ['dateTime' => $at->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i:s'), 'timeZone' => self::TIMEZONE];
    }

    /**
     * A creation carries its transactionId (a replay does not duplicate it)
     * and its place when there is one; an update (null) always sends the
     * place, so that a removed place is cleared.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withPlace(array $payload, ?string $location, ?string $transactionId): array
    {
        $place = $location !== null && trim($location) !== '' ? $location : null;
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
