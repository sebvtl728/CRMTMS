<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;

/**
 * One line of a fiche's « Historique ».
 */
final readonly class TimelineEntry
{
    /**
     * @param  string  $type  note | attachment | audit (later increments add their own)
     * @param  array<string, mixed>  $meta  type specific data, never personal values; meta.id (the row's uuid) is required: it orders the feed and builds the cursor
     */
    public function __construct(
        public string $type,
        public CarbonImmutable $at,
        public string $title,
        public ?string $body = null,
        public ?string $authorName = null,
        public array $meta = [],
    ) {}

    /**
     * The position of this entry, to ask for the entries after it.
     */
    public function cursor(): TimelineCursor
    {
        return TimelineCursor::of($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'at' => $this->at->toIso8601ZuluString('microsecond'),
            'title' => $this->title,
            'body' => $this->body,
            'author' => $this->authorName,
            'meta' => $this->meta,
        ];
    }
}
