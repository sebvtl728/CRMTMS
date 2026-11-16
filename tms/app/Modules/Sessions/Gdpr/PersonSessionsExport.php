<?php

namespace App\Modules\Sessions\Gdpr;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Sessions\TrainingSession;

/**
 * Sections of the RGPD export of a person (Crm\Public\FicheLinks): her
 * enrolments (session, training, dates, state, abandon and its reason,
 * adaptation needs, notes) and the dossiers she is the contractant
 * (particulier) or the contact of. Complete: trashed sessions and dossiers
 * included (flagged), whatever the viewer's scopes (the export is reserved
 * to users who see the whole organisme).
 */
final class PersonSessionsExport
{
    /**
     * @return array{inscriptions: list<array<string, mixed>>, dossiers: list<array<string, mixed>>}
     */
    public static function for(string $personId): array
    {
        $enrolments = Enrolment::query()->with(['session', 'dossier'])->where('person_id', $personId)
            ->orderBy('created_at')->orderBy('id')->get();
        $dossiers = Dossier::withTrashed()
            ->where(fn ($q) => $q->where('account_person_id', $personId)->orWhere('contact_person_id', $personId))
            ->orderBy('created_at')->orderBy('number')->get();

        $trainingIds = $enrolments->map(fn (Enrolment $e) => $e->session?->training_id)->filter()->unique()->values()->all();
        $trainings = app(Trainings::class)->findMany($trainingIds);
        $reasons = ReferenceItem::query()->whereKey($enrolments->pluck('abandon_reason_id')->filter()->unique()->values()->all())->pluck('label', 'id');

        return [
            'inscriptions' => $enrolments->map(function (Enrolment $e) use ($trainings, $reasons): array {
                /** @var TrainingSession|null $session */
                $session = $e->session;

                return [
                    'session' => $session?->number,
                    'formation' => $session === null ? null : ($trainings[$session->training_id]->title ?? null),
                    'debut_session' => $session?->starts_on?->toDateString(),
                    'fin_session' => $session?->ends_on?->toDateString(),
                    'dossier' => $e->dossier?->number,
                    'statut' => $e->status->label(),
                    'entree' => $e->entered_on?->toDateString(),
                    'sortie' => $e->left_on?->toDateString(),
                    'motif_abandon' => $e->abandon_reason_id === null ? null : ($reasons[$e->abandon_reason_id] ?? null),
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
                'supprime' => $d->trashed(),
            ])->values()->all(),
        ];
    }
}
