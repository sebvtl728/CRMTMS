<?php

namespace App\Modules\Sessions\Gdpr;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Sessions\TrainingSession;

/**
 * Sections of the RGPD export of a person (Crm\Public\FicheLinks): her
 * enrolments (session, training, dates, state, abandon and its reason, BPF
 * category, share of the price, adaptation needs, notes), the dossiers she
 * is the contractant (particulier, with lines and price) or the contact of,
 * and the fundings she pays (type, amount, state, agreement, notes). Complete: trashed sessions and dossiers
 * included (flagged), whatever the viewer's scopes (the export is reserved
 * to users who see the whole organisme).
 */
final class PersonSessionsExport
{
    /**
     * @return array{inscriptions: list<array<string, mixed>>, dossiers: list<array<string, mixed>>, financements: list<array<string, mixed>>}
     */
    public static function for(string $personId): array
    {
        $enrolments = Enrolment::query()->with(['session', 'dossier'])->where('person_id', $personId)
            ->orderBy('created_at')->orderBy('id')->get();
        $dossiers = Dossier::withTrashed()->with('lines')
            ->where(fn ($q) => $q->where('account_person_id', $personId)->orWhere('contact_person_id', $personId))
            ->orderBy('created_at')->orderBy('number')->get();
        $fundings = Funding::query()->where('funder_person_id', $personId)->orderBy('created_at')->orderBy('id')->get();
        $dossierNumbers = Dossier::withTrashed()->whereKey($fundings->pluck('dossier_id')->unique()->values()->all())->pluck('number', 'id');

        $trainingIds = $enrolments->map(fn (Enrolment $e) => $e->session?->training_id)->filter()->unique()->values()->all();
        $trainings = app(Trainings::class)->findMany($trainingIds);
        $labels = ReferenceItem::query()->whereKey($enrolments->pluck('abandon_reason_id')
            ->merge($enrolments->pluck('bpf_category_id'))->merge($fundings->pluck('funding_type_id'))
            ->filter()->unique()->values()->all())->pluck('label', 'id');

        return [
            'inscriptions' => $enrolments->map(function (Enrolment $e) use ($trainings, $labels): array {
                /** @var TrainingSession|null $session */
                $session = $e->session;

                return [
                    'session' => $session?->number,
                    'intitule_session' => $session?->title,
                    'formation' => $session === null ? null : ($trainings[$session->training_id]->title ?? null),
                    'debut_session' => $session?->starts_on?->toDateString(),
                    'fin_session' => $session?->ends_on?->toDateString(),
                    'dossier' => $e->dossier?->number,
                    'statut' => $e->status->label(),
                    'entree' => $e->entered_on?->toDateString(),
                    'sortie' => $e->left_on?->toDateString(),
                    'motif_abandon' => $e->abandon_reason_id === null ? null : ($labels[$e->abandon_reason_id] ?? null),
                    'categorie_bpf' => $e->bpf_category_id === null ? null : ($labels[$e->bpf_category_id] ?? null),
                    'part_du_prix' => $e->price_share,
                    'heures_prevues' => $e->planned_hours,
                    'besoins_adaptation' => $e->adaptation_needs,
                    'notes' => $e->notes,
                    'inscrite_le' => $e->created_at?->toIso8601String(),
                    'session_supprimee' => $session?->trashed() ?? false,
                ];
            })->values()->all(),
            'dossiers' => $dossiers->map(fn (Dossier $d): array => [
                'numero' => $d->number,
                'role' => $d->account_person_id === $personId ? 'Contractant (particulier)' : 'Contact',
                'statut' => $d->status->label(),
                'cree_le' => $d->created_at?->toIso8601String(),
                'notes' => $d->account_person_id === $personId ? $d->notes : null,
                // Her own contract (particulier): what she bought and its price.
                'montant_ht' => $d->account_person_id === $personId ? Amounts::decimal($d->priceCents()) : null,
                'lignes' => $d->account_person_id === $personId ? $d->lines->map(fn (DossierLine $l): array => [
                    'libelle' => $l->label,
                    'quantite' => $l->quantity,
                    'unite' => $l->unit,
                    'prix_unitaire' => $l->unit_price,
                    'remise_pourcent' => $l->discount_percent,
                    'total_ht' => $l->total_ht,
                ])->values()->all() : null,
                'supprime' => $d->trashed(),
            ])->values()->all(),
            'financements' => $fundings->map(fn (Funding $f): array => [
                'dossier' => $dossierNumbers[$f->dossier_id] ?? null,
                'type' => $labels[$f->funding_type_id] ?? null,
                'montant' => $f->amount,
                'etat' => $f->status->label(),
                'numero_accord' => $f->agreement_number,
                'date_accord' => $f->agreed_on?->toDateString(),
                'notes' => $f->notes,
            ])->values()->all(),
        ];
    }
}
