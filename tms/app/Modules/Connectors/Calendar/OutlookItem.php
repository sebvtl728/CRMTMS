<?php

namespace App\Modules\Connectors\Calendar;

use Carbon\CarbonImmutable;

/**
 * What a source (OutlookSources) puts in the Outlook calendar of one user:
 * the module decides the recipient and the text (never a personal datum
 * of someone else). Instants with their zone; the event is written in
 * Paris local time.
 */
final readonly class OutlookItem
{
    public function __construct(
        public string $userId,
        public string $subject,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?string $location,
        public string $body,
    ) {}
}
