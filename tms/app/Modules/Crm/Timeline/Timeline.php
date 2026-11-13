<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Merges the registered providers into one chronological feed.
 */
final class Timeline
{
    public const TAG = 'crm.timeline';

    /**
     * A cursor as the SQL literal to compare created_at with: stored values
     * are in the application timezone and keep their microseconds.
     */
    public static function bound(CarbonImmutable $instant): string
    {
        return $instant->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s.u');
    }

    /**
     * @return Collection<int, TimelineEntry> most recent first, at most $limit
     */
    public static function for(Model $subject, ?CarbonImmutable $before = null, int $limit = 30): Collection
    {
        $entries = collect();

        /** @var iterable<TimelineProvider> $providers */
        $providers = app()->tagged(self::TAG);

        foreach ($providers as $provider) {
            $entries = $entries->concat($provider->entriesFor($subject, $before, $limit));
        }

        return $entries
            ->sortByDesc(fn (TimelineEntry $e) => $e->at->format('Y-m-d H:i:s.u').'|'.$e->type.'|'.($e->meta['id'] ?? ''))
            ->take($limit)
            ->values();
    }
}
