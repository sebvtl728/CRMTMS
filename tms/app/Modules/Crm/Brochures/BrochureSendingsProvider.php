<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Timeline\TimelineCursor;
use App\Modules\Crm\Timeline\TimelineEntry;
use App\Modules\Crm\Timeline\TimelineProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * « Historique » of an entreprise or a person: « Plaquettes envoyées à
 * <destinataire> » at the date of the request, with the brochure titles and
 * the status (En attente / Envoyée / Échec). Never the address, the message
 * nor the server's text.
 */
final class BrochureSendingsProvider implements TimelineProvider
{
    public const TYPE = 'brochure_sending';

    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        if (! $subject instanceof Organisation && ! $subject instanceof Person) {
            return collect();
        }

        $sendings = BrochureSending::query()
            ->where($subject instanceof Organisation ? 'organisation_id' : 'person_id', $subject->getKey())
            ->tap(fn ($q) => Timeline::after($q, $before, self::TYPE))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return collect(BrochureSendingRows::of($sendings))->map(function (array $row) use ($sendings): TimelineEntry {
            $sending = $sendings->firstWhere('id', $row['id']);
            assert($sending instanceof BrochureSending);

            return new TimelineEntry(
                self::TYPE,
                CarbonImmutable::instance($sending->created_at),
                'Plaquettes envoyées à '.$row['recipient'],
                implode(', ', $row['titles']),
                $row['author'],
                ['id' => $row['id'], 'status' => $row['status'], 'status_label' => $row['status_label'], 'titles' => $row['titles']],
            );
        });
    }
}
