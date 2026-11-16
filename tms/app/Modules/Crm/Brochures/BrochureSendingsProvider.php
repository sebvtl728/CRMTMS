<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Timeline\TimelineCursor;
use App\Modules\Crm\Timeline\TimelineEntry;
use App\Modules\Crm\Timeline\TimelineProvider;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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

        $user = Auth::user();
        if (! $user instanceof User) {
            return collect();
        }

        // Sent from the fiche; for a person also those sent to her from a fiche the viewer sees.
        $sendings = BrochureSending::query()
            ->where(fn ($q) => $subject instanceof Organisation
                ? $q->where('organisation_id', $subject->getKey())
                : $q->where('person_id', $subject->getKey())->orWhere(fn ($r) => $r->where('recipient_person_id', $subject->getKey())
                    ->where(fn ($f) => $f->whereIn('organisation_id', CrmVisibility::organisations(Organisation::query(), $user)->select('organisations.id'))
                        ->orWhereIn('person_id', CrmVisibility::people(Person::query(), $user)->select('people.id')))))
            // Sent from an opportunity: only those the viewer sees (none deleted).
            ->where(fn ($q) => $q->whereNull('opportunity_id')
                ->orWhereIn('opportunity_id', CrmVisibility::opportunities(Opportunity::query(), $user)->select('opportunities.id')))
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
                ['id' => $row['id'], 'status' => $row['status'], 'status_label' => $row['status_label'], 'titles' => $row['titles'], 'versions' => $row['versions']],
            );
        });
    }
}
