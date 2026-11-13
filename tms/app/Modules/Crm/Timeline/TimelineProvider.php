<?php

namespace App\Modules\Crm\Timeline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A source of timeline entries (notes, attachments, audit, later
 * opportunities, actions, quotes, emails...). Registered under the container
 * tag Timeline::TAG.
 */
interface TimelineProvider
{
    /**
     * The $limit first entries of $subject that come strictly after $before
     * in the feed order (at desc, type desc, id desc), all from the start
     * when null. A provider with a single entry type filters its query with
     * Timeline::after().
     *
     * @return Collection<int, TimelineEntry>
     */
    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection;
}
