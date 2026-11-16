<?php

namespace App\Modules\Connectors\Calendar;

use Carbon\CarbonImmutable;

/**
 * One event of a published calendar (iCal feed), whatever module it comes
 * from: CRM actions (Crm\Activities\CalendarEvents) and the sources other
 * modules register (CalendarSources). $start is an instant, written in
 * Paris local time with its TZID; $minutes is its exact length.
 */
final readonly class CalendarEvent
{
    public function __construct(
        public string $uid,
        public string $title,
        public CarbonImmutable $start,
        public int $minutes,
        public ?string $location,
        public ?string $description,
        public string $url,
        public CarbonImmutable $updatedAt,
        public bool $cancelled = false,
    ) {}
}
