<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\CalendarEvent;
use App\Modules\Crm\Activities\CalendarEvents;
use Carbon\CarbonImmutable;

/**
 * The iCalendar (RFC 5545) feed of a user: his meetings and calls from 30
 * days back to 180 days ahead, in local Paris time (TZID with its
 * VTIMEZONE, so an event keeps its wall-clock time across DST changes).
 * The report of an action never leaves the tool.
 */
final class IcalFeed
{
    public const DAYS_BEFORE = 30;

    public const DAYS_AFTER = 180;

    /** Default length of an event without a duration, in minutes. */
    public const DEFAULT_MINUTES = ['meeting' => 60, 'call' => 15];

    private const TZID = 'Europe/Paris';

    private const CRLF = "\r\n";

    public static function for(User $user, ?CarbonImmutable $now = null): string
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::TZID)->startOfDay();
        $events = CalendarEvents::for($user, $today->subDays(self::DAYS_BEFORE), $today->addDays(self::DAYS_AFTER + 1)->startOfDay());

        return self::render($events, $now ?? CarbonImmutable::now());
    }

    /**
     * @param  list<CalendarEvent>  $events
     */
    public static function render(array $events, CarbonImmutable $now): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TMS//Agenda commercial//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text('Mon agenda TMS'),
            'X-WR-TIMEZONE:'.self::TZID,
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
            ...self::timezone(),
        ];

        foreach ($events as $event) {
            array_push($lines, ...self::event($event, $now));
        }
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(fn (string $line) => self::fold($line).self::CRLF, $lines));
    }

    /**
     * Splits a content line into lines of at most 75 octets, continuation
     * lines starting with a space (RFC 5545 §3.1), never inside a UTF-8
     * character.
     */
    public static function fold(string $line): string
    {
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > 75) {
                $out[] = $current;
                $current = ' '; // the leading space counts in the 75 octets
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode(self::CRLF, $out);
    }

    /**
     * TEXT value escaping (RFC 5545 §3.3.11): backslash, semicolon, comma
     * and line breaks.
     */
    public static function text(string $value): string
    {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $value);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * @return list<string>
     */
    private static function event(CalendarEvent $event, CarbonImmutable $now): array
    {
        $start = $event->start->setTimezone(self::TZID);
        $end = $start->addMinutes($event->durationMinutes ?? self::DEFAULT_MINUTES[$event->kind]);
        $local = fn (CarbonImmutable $at): string => $at->format('Ymd\THis');
        $utc = fn (CarbonImmutable $at): string => $at->utc()->format('Ymd\THis\Z');

        $description = array_filter([
            $event->fiche !== '' ? 'Fiche : '.$event->fiche : null,
            $event->opportunity !== null ? 'Opportunité : '.$event->opportunity : null,
            $event->result !== null ? 'Résultat : '.$event->result : null,
        ]);

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$event->id,
            'DTSTAMP:'.$utc($now),
            'LAST-MODIFIED:'.$utc($event->updatedAt),
            'DTSTART;TZID='.self::TZID.':'.$local($start),
            'DTEND;TZID='.self::TZID.':'.$local($end),
            'SUMMARY:'.self::text(trim($event->type.($event->fiche !== '' ? ' – '.$event->fiche : ''))),
        ];
        if ($event->location !== null && trim($event->location) !== '') {
            $lines[] = 'LOCATION:'.self::text($event->location);
        }
        if ($description !== []) {
            $lines[] = 'DESCRIPTION:'.self::text(implode("\n", $description));
        }
        $lines[] = 'URL:'.$event->url;
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Europe/Paris since 1996 (EU rules): summer time from the last Sunday
     * of March at 2:00 to the last Sunday of October at 3:00.
     *
     * @return list<string>
     */
    private static function timezone(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.self::TZID,
            'X-LIC-LOCATION:'.self::TZID,
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }
}
