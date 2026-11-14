<?php

namespace App\Modules\Crm\Activities;

use Carbon\CarbonImmutable;

/**
 * A meeting or a call as published outside the CRM (iCal feed): never its
 * report, which stays in the tool.
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
    ) {}
}
