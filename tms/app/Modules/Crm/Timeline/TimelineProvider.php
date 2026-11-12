<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A source of timeline entries (notes, attachments, audit, later quotes,
 * emails...). Registered under the container tag Timeline::TAG.
 */
interface TimelineProvider
{
    /**
     * The $limit most recent entries of $subject strictly older than
     * $before (all when null), most recent first.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function entriesFor(Model $subject, ?CarbonImmutable $before, int $limit): Collection;
}
