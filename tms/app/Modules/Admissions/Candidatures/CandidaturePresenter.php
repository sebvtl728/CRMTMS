<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Public\Admission;
use App\Modules\Sessions\Public\Billing;
use Carbon\CarbonImmutable;

/**
 * A candidature as its page shows it. The identity of the person comes from
 * the CRM (Crm\Public), « Personne anonymisée » once anonymised; the link to
 * the CRM fiche only when the CRM lets the viewer open it. The sensitive
 * data are absent (not merely hidden) without admissions.sensitive.view.
 */
final class CandidaturePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function show(Candidature $c, User $viewer): array
    {
        $profile = app(Trainees::class)->profile($c->person_id);
        $openable = app(Fiches::class)->openable($viewer, 'person', [$c->person_id]);
        $training = $c->training_id !== null ? app(Trainings::class)->find($c->training_id) : null;
        $session = $c->target_session_id !== null ? Admission::session($c->target_session_id) : null;
        $labels = ReferenceItem::withTrashed()->whereKey(array_filter([$c->das_id, $c->origin_id, $c->decision_reason_id]))->pluck('label', 'id');

        $data = [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'pathway' => $c->pathway?->value,
            'pathway_label' => $c->pathway?->label(),
            'person' => [
                'id' => $c->person_id,
                'label' => $profile['label'] ?? 'Personne anonymisée',
                'anonymised' => $profile === null,
                'trashed' => $profile['trashed'] ?? false,
                'email' => $profile['email'] ?? null,
                'phone' => $profile['phone'] ?? null,
                'mobile' => $profile['mobile'] ?? null,
                'birth_date' => $profile['birth_date'] ?? null,
                'education_level_id' => $profile['education_level_id'] ?? null,
                'professional_situation_id' => $profile['professional_situation_id'] ?? null,
                'crm_href' => isset($openable[$c->person_id]) ? "/crm/people/{$c->person_id}" : null,
            ],
            'training' => $training === null ? null : ['id' => $training->id, 'title' => $training->title, 'rncp_code' => $training->rncp_code],
            'rncp_code' => $c->rncp_code,
            'target_session' => $session === null ? null : ['id' => $session['id'], 'label' => $session['label'], 'starts_on' => $session['starts_on']],
            'das' => ['id' => $c->das_id, 'label' => $labels[$c->das_id] ?? null],
            'responsible_user_id' => $c->responsible_user_id,
            'origin_id' => $c->origin_id,
            'origin_label' => $c->origin_id !== null ? ($labels[$c->origin_id] ?? null) : null,
            'prescriber' => $c->prescriber_organisation_id === null ? null
                : ['id' => $c->prescriber_organisation_id, 'label' => app(Fiches::class)->organisationLabels([$c->prescriber_organisation_id])[$c->prescriber_organisation_id]['label'] ?? '—'],
            'france_travail_id' => $c->france_travail_id,
            'last_diploma' => $c->last_diploma,
            'legal_representative' => $c->legal_representative,
            'positioning_sent_on' => $c->positioning_sent_on?->toDateString(),
            'positioning_received_on' => $c->positioning_received_on?->toDateString(),
            'positioning_result' => $c->positioning_result,
            'interview_at' => $c->interview_at?->setTimezone('Europe/Paris')->format('Y-m-d\TH:i'),
            'interviewer_user_id' => $c->interviewer_user_id,
            'decision_reason_id' => $c->decision_reason_id,
            'decision_reason_label' => $c->decision_reason_id !== null ? ($labels[$c->decision_reason_id] ?? null) : null,
            'dossier_id' => $c->dossier_id,
            'dossier' => self::dossier($c),
            'alternance' => AlternanceWriter::forPage($c),
            'files' => CandidatureFile::query()->where('candidature_id', $c->id)->orderBy('created_at')->get()
                ->map(fn (CandidatureFile $f) => [
                    'id' => $f->id, 'kind' => $f->kind, 'name' => $f->name, 'size' => $f->size,
                    'created_at' => $f->created_at?->toIso8601String(), 'href' => "/admissions/candidatures/{$c->id}/files/{$f->id}",
                ])->values()->all(),
            'is_minor' => self::isMinor($c, $profile['birth_date'] ?? null, $session['starts_on'] ?? null),
            'deleted_at' => $c->deleted_at?->toIso8601String(),
        ];

        if (AdmissionsVisibility::sensitive($viewer)) {
            $data['rqth'] = $c->rqth;
            $data['adaptation_needs'] = $c->adaptation_needs;
            $data['interview_report'] = $c->interview_report;
        }

        return $data;
    }

    /**
     * The dossier of the candidature (number, link, in the trash or not), null without one.
     *
     * @return array{id: string, number: string, href: string, trashed: bool}|null
     */
    public static function dossier(Candidature $c): ?array
    {
        $found = $c->dossier_id !== null ? Billing::numbers([$c->dossier_id])[$c->dossier_id] ?? null : null;

        return $found === null ? null : ['id' => (string) $c->dossier_id, 'number' => $found['number'], 'href' => "/dossiers/{$c->dossier_id}", 'trashed' => $found['trashed']];
    }

    /**
     * Under 18 on the reference day: the start of the contract, else of the
     * targeted session, else today (Paris days).
     */
    public static function isMinor(Candidature $c, ?string $birthDate, ?string $sessionStart): bool
    {
        if ($birthDate === null) {
            return false;
        }
        $contractStart = $c->relationLoaded('contract') || $c->exists ? $c->contract?->starts_on?->toDateString() : null;
        $on = CarbonImmutable::parse($contractStart ?? $sessionStart ?? CarbonImmutable::now('Europe/Paris')->toDateString(), 'Europe/Paris');

        return CarbonImmutable::parse($birthDate, 'Europe/Paris')->addYears(18)->greaterThan($on);
    }
}
