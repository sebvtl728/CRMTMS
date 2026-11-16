<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Rows of the dossiers list and the header of the dossier page: the
 * contractant (Crm\Public\Fiches, with whether $viewer may open its fiche:
 * a link then, plain text otherwise), the trainings of the lines
 * (Catalogue\Trainings), the DAS, the price in French, the effective state
 * of $today. One query per source for a page.
 */
final class DossierRows
{
    /**
     * @param  iterable<Dossier>  $dossiers
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $dossiers, User $viewer, CarbonImmutable $today): array
    {
        $dossiers = (new EloquentCollection(Collection::make($dossiers)->all()))->loadMissing(['responsible:id,name', 'lines']);
        $fiches = app(Fiches::class);
        $organisationIds = $dossiers->pluck('account_organisation_id')->filter()->unique()->values()->all();
        $personIds = $dossiers->pluck('account_person_id')->filter()->unique()->values()->all();
        $organisations = $fiches->organisationLabels($organisationIds);
        $people = $fiches->personLabels($personIds);
        $openable = [
            'organisation' => $fiches->openable($viewer, 'organisation', $organisationIds),
            'person' => $fiches->openable($viewer, 'person', $personIds),
        ];
        $trainings = app(Trainings::class)->findMany($dossiers->flatMap(fn (Dossier $d) => $d->lines->pluck('training_id'))->filter()->unique()->values()->all());
        $das = ReferenceItem::query()->whereKey($dossiers->pluck('das_id')->unique()->values()->all())->pluck('label', 'id')->all();
        $spans = DossierSchedule::spans($dossiers->pluck('id')->all());

        return $dossiers->map(function (Dossier $d) use ($organisations, $people, $openable, $trainings, $das, $spans, $today): array {
            $type = $d->accountType();
            $identity = ($type === 'organisation' ? $organisations : $people)[$d->accountId()] ?? null;
            $status = $d->effectiveStatus($today, $spans[$d->id] ?? null);
            $price = $d->priceCents();

            return [
                'id' => $d->id,
                'number' => $d->number,
                'account' => [
                    'type' => $type,
                    'id' => $d->accountId(),
                    'label' => $identity['label'] ?? 'Fiche introuvable',
                    'trashed' => $identity['trashed'] ?? false,
                    'can_open' => isset($openable[$type][$d->accountId()]),
                ],
                'training_titles' => $d->lines->filter(fn (DossierLine $l) => $l->isTraining())
                    ->map(fn (DossierLine $l) => isset($trainings[(string) $l->training_id]) ? $trainings[(string) $l->training_id]->title : $l->label)
                    ->unique()->values()->all(),
                'das' => $das[$d->das_id] ?? null,
                'price_cents' => $price,
                'price' => Amounts::display($price),
                'responsible' => $d->responsible?->name,
                'status' => $status->value,
                'status_label' => $status->label(),
                'created_on' => $d->created_at?->setTimezone('Europe/Paris')->toDateString(),
                'trashed' => $d->trashed(),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(Dossier $dossier, User $viewer, CarbonImmutable $today): array
    {
        return self::rows([$dossier], $viewer, $today)[0];
    }
}
