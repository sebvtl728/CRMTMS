<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Fundings\FundingBalance;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Rows of the dossiers list and the header of the dossier page: the
 * contractant (Crm\Public\Fiches, with whether $viewer may open its fiche:
 * a link then, plain text otherwise), the trainings of the lines
 * (Catalogue\Trainings), the DAS, the price and the remainder to fund in
 * French, the effective state
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
        $funded = FundingBalance::fundedOf($dossiers->pluck('id')->all());

        return $dossiers->map(function (Dossier $d) use ($organisations, $people, $openable, $trainings, $das, $spans, $funded, $today): array {
            $type = $d->accountType();
            $identity = ($type === 'organisation' ? $organisations : $people)[$d->accountId()] ?? null;
            // Spans read for the page: a dossier without one has no session yet (no query of its own).
            $span = $spans[$d->id] ?? null;
            $status = $d->status->effective($span['starts_on'] ?? null, $span['ends_on'] ?? null, $today);
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
                // « Reste à financer »: price − fundings not refused (negative: fundings above the price).
                'remaining_cents' => $price - ($funded[$d->id] ?? 0),
                'remaining' => FundingBalance::cell($price - ($funded[$d->id] ?? 0)),
                'responsible' => $d->responsible?->name,
                'status' => $status->value,
                'status_label' => $status->label(),
                'created_on' => $d->created_at?->setTimezone('Europe/Paris')->toDateString(),
                'trashed' => $d->trashed(),
            ];
        })->values()->all();
    }

    /**
     * « Dossiers » of the fiche of an entreprise: the dossiers it is the
     * contractant of that the viewer sees (sessions.dossiers.view and its
     * scope), latest first; null without that right.
     *
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>}|null
     */
    public static function organisationBlock(string $organisationId, User $viewer): ?array
    {
        return self::accountBlock('account_organisation_id', $organisationId, $viewer, 'Aucun dossier de formation pour cette entreprise.');
    }

    /**
     * Same « Dossiers » on the fiche of a person: the dossiers she is the
     * contractant (particulier) of. Shown for a particulier, or a person
     * contractant of a dossier (flag removed since); null otherwise.
     *
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>}|null
     */
    public static function personBlock(string $personId, User $viewer): ?array
    {
        if (! $viewer->can(Permissions::DOSSIERS_VIEW)) {
            return null;
        }
        if (app(Fiches::class)->individual($personId) === null
            && ! Dossier::withTrashed()->where('account_person_id', $personId)->exists()) {
            return null;
        }

        return self::accountBlock('account_person_id', $personId, $viewer, 'Aucun dossier de formation pour ce particulier.');
    }

    /**
     * @param  'account_organisation_id'|'account_person_id'  $column
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>}|null
     */
    private static function accountBlock(string $column, string $id, User $viewer, string $empty): ?array
    {
        if (! $viewer->can(Permissions::DOSSIERS_VIEW)) {
            return null;
        }
        $dossiers = SessionsVisibility::dossiers(Dossier::query(), $viewer)
            ->where("dossiers.{$column}", $id)
            ->orderByDesc('dossiers.created_at')->orderByDesc('dossiers.number')
            ->limit(100)->get();

        return [
            'title' => 'Dossiers',
            'empty' => $empty,
            'rows' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'title' => "Dossier {$row['number']}",
                'subtitle' => $row['training_titles'] === [] ? null : implode(', ', $row['training_titles']),
                'meta' => "{$row['price']} HT",
                'badge' => $row['status_label'],
                'href' => route('dossiers.show', $row['id'], false),
            ], self::rows($dossiers, $viewer, Dossier::today())),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(Dossier $dossier, User $viewer, CarbonImmutable $today): array
    {
        return self::rows([$dossier], $viewer, $today)[0];
    }
}
