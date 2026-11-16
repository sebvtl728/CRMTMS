<?php

namespace App\Modules\Crm\Activities;

use Carbon\CarbonImmutable;

/**
 * A meeting or a call as published outside the CRM (iCal feed, Outlook):
 * never its report nor its next steps, which stay in the tool. Its purpose
 * (« Motif ») is shown by Outlook only.
 */
final readonly class CalendarEvent
{
    /**
     * @param  'meeting'|'call'  $kind
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $type,
        public string $fiche,
        public ?string $opportunity,
        public ?string $result,
        public ?string $location,
        public CarbonImmutable $start,
        public ?int $durationMinutes,
        public string $url,
        public CarbonImmutable $updatedAt,
        public ?string $responsibleUserId = null,
        public ?string $purpose = null,
    ) {}
}
