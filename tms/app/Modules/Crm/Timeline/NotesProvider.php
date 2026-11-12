<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class NotesProvider implements TimelineProvider
{
    public function entriesFor(Model $subject, ?CarbonImmutable $before, int $limit): Collection
    {
        return Note::query()->with('author')
            ->where('notable_type', $subject->getMorphClass())->where('notable_id', $subject->getKey())
            ->when($before, fn ($q) => $q->where('created_at', '<', Timeline::bound($before)))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Note $n) => new TimelineEntry(
                'note',
                CarbonImmutable::instance($n->created_at),
                'Note',
                $n->body,
                $n->author?->name,
                ['id' => $n->id],
            ));
    }
}
