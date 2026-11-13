<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Merges the registered providers into one feed, ordered by
 * (at desc, type desc, id desc): a total order, so paging with a
 * TimelineCursor neither loses nor repeats an entry.
 */
final class Timeline
{
    public const TAG = 'crm.timeline';

    /**
     * An instant as the SQL literal to compare a timestamp with: stored values
     * are in the application timezone and keep their microseconds.
     */
    public static function bound(CarbonImmutable $instant): string
    {
        return $instant->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s.u');
    }

    /**
     * Restricts a provider's query (all rows of entry type $type) to the rows
     * that come after $before in the feed order. Order the query by
     * $atColumn desc then $idColumn desc.
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function after(QueryBuilder $query, ?TimelineCursor $before, string $type, string $atColumn = 'created_at', string $idColumn = 'id'): QueryBuilder
    {
        if ($before === null) {
            return $query;
        }

        $at = self::bound($before->at);
        $cmp = strcmp($type, $before->type);

        if ($cmp < 0) {
            // Same instant, smaller type: comes after the cursor.
            return $query->where($atColumn, '<=', $at);
        }
        if ($cmp > 0) {
            return $query->where($atColumn, '<', $at);
        }

        return $query->where(fn ($q) => $q->where($atColumn, '<', $at)
            ->orWhere(fn ($same) => $same->where($atColumn, '=', $at)->where($idColumn, '<', $before->id)));
    }

    /**
     * @return Collection<int, TimelineEntry> in feed order, at most $limit
     */
    public static function for(Model $subject, ?TimelineCursor $before = null, int $limit = 30): Collection
    {
        $entries = collect();

        /** @var iterable<TimelineProvider> $providers */
        $providers = app()->tagged(self::TAG);

        foreach ($providers as $provider) {
            $entries = $entries->concat($provider->entriesFor($subject, $before, $limit));
        }

        return $entries
            ->sort(fn (TimelineEntry $a, TimelineEntry $b) => self::compare($b, $a))
            ->take($limit)
            ->values();
    }

    /**
     * Feed order as in SQL: UTC instant with microseconds, type, then the id
     * (lowercase UUIDs compare as text like PostgreSQL's uuid). Byte-wise
     * comparisons: never PHP's numeric-string juggling.
     */
    private static function compare(TimelineEntry $a, TimelineEntry $b): int
    {
        return strcmp($a->at->utc()->format('Y-m-d H:i:s.u'), $b->at->utc()->format('Y-m-d H:i:s.u'))
            ?: strcmp($a->type, $b->type)
            ?: strcmp(strtolower((string) ($a->meta['id'] ?? '')), strtolower((string) ($b->meta['id'] ?? '')));
    }
}
