<?php

namespace App\Modules\Sessions\Fundings;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Collection;

/**
 * « Financements » section of the dossier page (shown to whoever sees the
 * dossier, never on a session page): the fundings with their funder (a link
 * when the viewer can open the fiche: Fiches::openable), the balance with
 * its alerts (FundingBalance), and the options of the form.
 */
final class FundingRows
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(Dossier $dossier, User $viewer, bool $canUpdate): array
    {
        $fundings = $dossier->fundings()->get();
        if ($fundings->isEmpty()) {
            return [];
        }
        $fiches = app(Fiches::class);
        $organisationIds = $fundings->pluck('funder_organisation_id')->filter()->unique()->values()->all();
        $personIds = $fundings->pluck('funder_person_id')->filter()->unique()->values()->all();
        $labels = [
            'organisation' => $fiches->organisationLabels($organisationIds),
            'person' => $fiches->personLabels($personIds),
        ];
        $openable = [
            'organisation' => $fiches->openable($viewer, 'organisation', $organisationIds),
            'person' => $fiches->openable($viewer, 'person', $personIds),
        ];
        $types = ReferenceItem::query()->whereKey($fundings->pluck('funding_type_id')->unique()->values()->all())->pluck('label', 'id');
        $targets = self::targetLabels($dossier, $fundings);

        return $fundings->map(function (Funding $f) use ($labels, $openable, $types, $targets, $canUpdate): array {
            $type = $f->funderType();
            $id = $f->funderId();

            return [
                'id' => $f->id,
                'funder' => [
                    'type' => $type,
                    'id' => $id,
                    'label' => $labels[$type][$id]['label'] ?? 'Fiche introuvable',
                    'trashed' => $labels[$type][$id]['trashed'] ?? false,
                    'can_open' => isset($openable[$type][$id]),
                ],
                'type' => $types[$f->funding_type_id] ?? null,
                'target' => $f->dossier_line_id !== null
                    ? ['kind' => 'line', 'id' => $f->dossier_line_id, 'label' => $targets['lines'][$f->dossier_line_id] ?? 'Ligne']
                    : ($f->enrolment_id !== null ? ['kind' => 'enrolment', 'id' => $f->enrolment_id, 'label' => $targets['enrolments'][$f->enrolment_id] ?? 'Inscription'] : null),
                'amount' => Amounts::display(Amounts::cents((string) $f->amount)),
                'status' => $f->status->value,
                'status_label' => $f->status->label(),
                'agreement_number' => $f->agreement_number,
                'agreed_on' => $f->agreed_on?->toDateString(),
                'notes' => $f->notes,
                'input' => $canUpdate ? FundingWriter::input($f) : null,
            ];
        })->values()->all();
    }

    /**
     * The balance with its French messages: dossier, then each line and each
     * enrolment targeted (« Inscription annulée » flagged).
     *
     * @return array<string, mixed>
     */
    public static function balance(Dossier $dossier): array
    {
        $balance = FundingBalance::for($dossier);
        $fundings = $dossier->fundings()->get(['id', 'dossier_line_id', 'enrolment_id']);
        $targets = self::targetLabels($dossier, $fundings);
        $part = fn (array $p, string $label, bool $cancelled = false) => [
            'label' => $label,
            'price' => Amounts::display($p['price_cents']),
            'funded' => Amounts::display($p['funded_cents']),
            'remaining_cents' => $p['remaining_cents'],
            'message' => FundingBalance::message($p['remaining_cents'], $cancelled, $p['funded_cents']),
        ];

        return [
            'price' => Amounts::display($balance['price_cents']),
            'funded' => Amounts::display($balance['funded_cents']),
            'remaining_cents' => $balance['remaining_cents'],
            'message' => FundingBalance::message($balance['remaining_cents']),
            'balanced' => $balance['remaining_cents'] === 0,
            'parts' => [
                ...array_map(fn (string $id, array $p) => ['kind' => 'line', ...$part($p, $targets['lines'][$id] ?? 'Ligne'), 'cancelled' => false], array_keys($balance['lines']), $balance['lines']),
                ...array_map(fn (string $id, array $p) => ['kind' => 'enrolment', ...$part($p, $targets['enrolments'][$id] ?? 'Inscription', $p['cancelled']), 'cancelled' => $p['cancelled']], array_keys($balance['enrolments']), $balance['enrolments']),
            ],
        ];
    }

    /**
     * Options of the form: types of funder, states, the lines of the dossier
     * and the enrolments that can be targeted (lines priced per trainee, not
     * cancelled nor waitlisted; those already targeted kept).
     *
     * @return array<string, mixed>
     */
    public static function options(Dossier $dossier): array
    {
        $fundings = $dossier->fundings()->get(['id', 'funding_type_id', 'dossier_line_id', 'enrolment_id']);
        $kept = $fundings->pluck('enrolment_id')->filter()->all();
        $perTrainee = $dossier->lines->where('pricing_mode', DossierLine::PER_TRAINEE)->pluck('id')->all();
        $enrolments = Enrolment::query()->where('dossier_id', $dossier->id)
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->whereIn('dossier_line_id', $perTrainee)
                ->whereNotIn('status', [EnrolmentStatus::Cancelled->value, EnrolmentStatus::Waitlisted->value]))
                ->orWhereIn('id', $kept))
            ->orderBy('created_at')->orderBy('id')->get();
        $labels = self::enrolmentLabels($enrolments);

        return [
            'type' => SessionListQuery::reference(ReferenceList::FundingType, $fundings->pluck('funding_type_id')->all()),
            'status' => FundingStatus::options(),
            'line' => $dossier->lines->map(fn (DossierLine $l) => ['value' => $l->id, 'label' => $l->label])->values()->all(),
            'enrolment' => $enrolments->map(fn (Enrolment $e) => ['value' => $e->id, 'label' => $labels[$e->id]])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Funding>  $fundings
     * @return array{lines: array<string, string>, enrolments: array<string, string>}
     */
    private static function targetLabels(Dossier $dossier, Collection $fundings): array
    {
        $enrolmentIds = $fundings->pluck('enrolment_id')->filter()->unique()->values()->all();

        return [
            'lines' => $dossier->lines->mapWithKeys(fn (DossierLine $l) => [$l->id => $l->label])->all(),
            'enrolments' => $enrolmentIds === [] ? [] : self::enrolmentLabels(Enrolment::query()->whereKey($enrolmentIds)->get()),
        ];
    }

    /**
     * « Léa Durand · S-2026-0001 », « (annulée) » for a cancelled enrolment.
     *
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, string>
     */
    private static function enrolmentLabels(Collection $enrolments): array
    {
        $people = app(Fiches::class)->personLabels($enrolments->pluck('person_id')->unique()->values()->all());
        $sessions = TrainingSession::withTrashed()->whereKey($enrolments->pluck('training_session_id')->unique()->values()->all())->pluck('number', 'id');

        return $enrolments->mapWithKeys(fn (Enrolment $e) => [$e->id => ($people[$e->person_id]['label'] ?? 'Fiche introuvable')
            .' · '.($sessions[$e->training_session_id] ?? 'Session')
            .($e->isCancelled() ? ' (inscription annulée)' : '')])->all();
    }
}
