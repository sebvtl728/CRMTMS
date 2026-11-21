<?php

namespace App\Modules\Sessions\Gdpr;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;

/**
 * Sections of the RGPD export of a person (Crm\Public\FicheLinks): her
 * enrolments (session, training, dates, state, abandon and its reason, BPF
 * category, share of the price, adaptation needs, notes), the dossiers she
 * is the contractant (particulier, with lines and price) or the contact of,
 * the fundings she pays (type, amount, state, agreement, notes), her attendance
 * (séance, state, minutes, reason) and her monthly hours (0.8.0). Complete: trashed sessions and dossiers
 * included (flagged), whatever the viewer's scopes (the export is reserved
 * to users who see the whole organisme).
 */
final class PersonSessionsExport
{
    /**
     * @return array{presences: list<array<string, mixed>>, emargements: list<array<string, mixed>>, heures_mensuelles: list<array<string, mixed>>, inscriptions: list<array<string, mixed>>, dossiers: list<array<string, mixed>>, financements: list<array<string, mixed>>}
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

        $enrolmentIds = $enrolments->pluck('id')->all();
        $attendances = Attendance::query()->whereIn('enrolment_id', $enrolmentIds)->get();
        $seances = Seance::withTrashed()->whereKey($attendances->pluck('seance_id')->unique()->values()->all())->get()->keyBy('id');
        $hours = MonthlyHour::query()->whereIn('enrolment_id', $enrolmentIds)->orderBy('month')->get();
        $modules = SessionModule::withTrashed()->whereKey($seances->pluck('session_module_id')->merge($hours->pluck('session_module_id'))->unique()->values()->all())->pluck('title', 'id');
        $sessionOf = $enrolments->pluck('session', 'id');

        $signatures = \App\Modules\Sessions\Attendance\Signing\AttendanceSignature::query()->whereIn('enrolment_id', $enrolmentIds)->orderBy('signed_at')->get();
        $slots = \App\Modules\Sessions\Attendance\Signing\SignSlot::query()->whereKey($signatures->pluck('slot_id')->unique()->values()->all())->get()->keyBy('id');

        return [
            'emargements' => $signatures->map(fn ($sig): array => [
                'session' => $sessionOf[$sig->enrolment_id]?->number,
                'date' => $slots[$sig->slot_id]?->date->toDateString(),
                'demi_journee' => \App\Modules\Sessions\Attendance\Signing\SignSlot::label((string) $slots[$sig->slot_id]?->half),
                'signe_le' => $sig->signed_at?->toIso8601String(),
                'source' => $sig->source,
            ])->values()->all(),
            'presences' => $attendances->sortBy(fn (Attendance $a) => $seances[$a->seance_id]->date->toDateString().$seances[$a->seance_id]->starts_at.$a->id)
                ->map(fn (Attendance $a): array => [
                    'session' => $sessionOf[$a->enrolment_id]?->number,
                    'module' => $modules[$seances[$a->seance_id]->session_module_id] ?? null,
                    'date' => $seances[$a->seance_id]->date->toDateString(),
                    'debut' => $seances[$a->seance_id]->start(),
                    'fin' => $seances[$a->seance_id]->end(),
                    'statut' => Attendance::label($a->status),
                    'minutes' => $a->minutes,
                    'motif' => $a->reason,
                    'seance_annulee' => $seances[$a->seance_id]->isCancelled(),
                ])->values()->all(),
            'heures_mensuelles' => $hours->map(fn (MonthlyHour $h): array => [
                'session' => $sessionOf[$h->enrolment_id]?->number,
                'module' => $modules[$h->session_module_id] ?? null,
                'mois' => $h->monthKey(),
                'minutes' => $h->minutes,
            ])->values()->all(),
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
