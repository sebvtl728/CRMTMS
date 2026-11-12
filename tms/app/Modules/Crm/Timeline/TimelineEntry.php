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
     * @param  array<string, mixed>  $meta  type specific data (ids, size...), never personal values
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
