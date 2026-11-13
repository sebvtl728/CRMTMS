<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class AttachmentsProvider implements TimelineProvider
{
    public function entriesFor(Model $subject, ?CarbonImmutable $before, int $limit): Collection
    {
        return Attachment::query()->with('uploader')
            ->where('notable_type', $subject->getMorphClass())->where('notable_id', $subject->getKey())
            ->when($before, fn ($q) => $q->where('created_at', '<', Timeline::bound($before)))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Attachment $a) => new TimelineEntry(
                'attachment',
                CarbonImmutable::instance($a->created_at),
                $a->original_name,
                null,
                $a->uploader?->name,
                ['id' => $a->id, 'size' => $a->size, 'mime' => $a->mime],
            ));
    }
}
