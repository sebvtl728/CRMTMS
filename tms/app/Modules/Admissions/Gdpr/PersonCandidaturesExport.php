<?php

namespace App\Modules\Admissions\Gdpr;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Booklet\BookletAnonymisation;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\CustomFields\CustomFieldDefinition;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;

/**
 * Section « candidatures » of the RGPD export of a person: every candidature
 * (trash included) with its personal and sensitive data, the contract and
 * the custom values under their labels (hidden fields included).
 */
final class PersonCandidaturesExport
{
    /**
     * @return array{candidatures: list<array<string, mixed>>}
     */
    public static function for(string $personId): array
    {
        $candidatures = Candidature::withTrashed()->where('person_id', $personId)->orderBy('created_at')->get();
        $labels = CustomFieldDefinition::query()->pluck('label', 'id');
        $reasons = ReferenceItem::withTrashed()->whereKey(array_values(array_filter($candidatures->pluck('decision_reason_id')->all())))->pluck('label', 'id');
        $trainings = app(Trainings::class)->findMany(array_values(array_filter($candidatures->pluck('training_id')->unique()->all())));
        $contracts = AlternanceContract::query()->whereIn('candidature_id', $candidatures->pluck('id'))->get()->keyBy('candidature_id');

        return ['candidatures' => $candidatures->map(function (Candidature $c) use ($labels, $reasons, $trainings, $contracts): array {
            $custom = [];
            foreach ($c->custom_values ?? [] as $id => $value) {
                $custom[(string) ($labels[$id] ?? $id)] = $value;
            }
            $contract = $contracts->get($c->id);

            return [
                'numero' => $c->number,
                'etape' => $c->status->label(),
                'formation' => $c->training_id !== null ? ($trainings[$c->training_id]->title ?? null) : null,
                'code_rncp' => $c->rncp_code,
                'identifiant_france_travail' => $c->france_travail_id,
                'dernier_diplome' => $c->last_diploma,
                'representant_legal' => $c->legal_representative,
                'rqth' => $c->rqth,
                'besoins_adaptation' => $c->adaptation_needs,
                'test_envoye_le' => $c->positioning_sent_on?->toDateString(),
                'test_recu_le' => $c->positioning_received_on?->toDateString(),
                'resultat_test' => $c->positioning_result,
                'entretien_le' => $c->interview_at?->toIso8601String(),
                'compte_rendu_entretien' => $c->interview_report,
                'motif_non_admission' => $c->decision_reason_id !== null ? ($reasons[$c->decision_reason_id] ?? null) : null,
                'contrat' => $contract === null ? null : [
                    'type' => $contract->contract_type->label(),
                    'debut' => $contract->starts_on->toDateString(),
                    'fin' => $contract->ends_on->toDateString(),
                ],
                'champs_personnalises' => $custom,
                'livret' => $contract === null ? null : BookletAnonymisation::export($c->id),
                'creee_le' => $c->created_at?->toIso8601String(),
                'supprimee' => $c->trashed(),
            ];
        })->values()->all()];
    }
}
