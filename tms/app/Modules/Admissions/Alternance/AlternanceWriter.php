<?php

namespace App\Modules\Admissions\Alternance;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The alternance contract of a candidature (one at most), recorded from the
 * interview on; removed only while no dossier exists. The employer, the
 * tutor and the OPCO are CRM fiches out of the trash (Crm\Public\Fiches).
 */
final class AlternanceWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Candidature $candidature, array $data, User $by): AlternanceContract
    {
        $rank = $candidature->status->rank();
        if ($rank === null || $rank < CandidatureStatus::Interview->rank()) {
            throw new UserFacingHttpException(409, 'Le contrat se saisit à partir de l\'entretien.');
        }
        $data = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $data);

        $validator = Validator::make($data, [
            'contract_type' => ['required', Rule::enum(ContractType::class)],
            'employer_organisation_id' => ['required', 'uuid'],
            'tutor_person_id' => ['nullable', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'opco_organisation_id' => ['nullable', 'uuid'],
        ], [
            'required' => 'Ce champ est obligatoire.',
            'uuid' => 'Valeur inconnue.',
            'date_format' => 'Date invalide.',
            'contract_type.enum' => 'Type de contrat inconnu.',
        ]);
        $validator->after(function ($v) use ($data): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if ($data['ends_on'] < $data['starts_on']) {
                $v->errors()->add('ends_on', 'La fin du contrat doit suivre son début.');
            }
            $fiches = app(Fiches::class);
            if ($fiches->organisation($data['employer_organisation_id']) === null) {
                $v->errors()->add('employer_organisation_id', 'Choisissez une entreprise du CRM.');
            }
            if (($data['opco_organisation_id'] ?? null) !== null && $fiches->organisation($data['opco_organisation_id']) === null) {
                $v->errors()->add('opco_organisation_id', 'Choisissez une entreprise du CRM.');
            }
            if (($data['tutor_person_id'] ?? null) !== null && $fiches->person($data['tutor_person_id']) === null) {
                $v->errors()->add('tutor_person_id', 'Choisissez une personne du CRM.');
            }
        });
        $values = $validator->validate();

        return DB::transaction(function () use ($candidature, $values): AlternanceContract {
            Candidature::query()->lockForUpdate()->findOrFail($candidature->getKey());
            $contract = AlternanceContract::query()->updateOrCreate(['candidature_id' => $candidature->id], [
                'contract_type' => $values['contract_type'],
                'employer_organisation_id' => $values['employer_organisation_id'],
                'tutor_person_id' => $values['tutor_person_id'] ?? null,
                'starts_on' => $values['starts_on'],
                'ends_on' => $values['ends_on'],
                'opco_organisation_id' => $values['opco_organisation_id'] ?? null,
            ]);
            $candidature->touch();

            return $contract;
        });
    }

    public function delete(Candidature $candidature): void
    {
        if ($candidature->dossier_id !== null && Admission::dossierState($candidature->dossier_id) === 'live') {
            throw new UserFacingHttpException(409, 'Le dossier est créé : le contrat ne peut plus être retiré.');
        }
        DB::transaction(function () use ($candidature): void {
            AlternanceContract::query()->where('candidature_id', $candidature->id)->first()?->delete();
            $candidature->touch();
        });
    }

    /**
     * The contract as the candidature page shows it, null without one.
     *
     * @return array<string, mixed>|null
     */
    public static function forPage(Candidature $candidature): ?array
    {
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first();
        if ($contract === null) {
            return null;
        }
        $fiches = app(Fiches::class);
        $orgs = $fiches->organisationLabels(array_values(array_filter([$contract->employer_organisation_id, $contract->opco_organisation_id])));
        $tutor = $contract->tutor_person_id !== null ? $fiches->personLabels([$contract->tutor_person_id])[$contract->tutor_person_id] ?? null : null;
        $org = fn (?string $id) => $id === null ? null : ['id' => $id, 'label' => $orgs[$id]['label'] ?? '—'];

        return [
            'contract_type' => $contract->contract_type->value,
            'contract_type_label' => $contract->contract_type->label(),
            'employer' => $org($contract->employer_organisation_id),
            'tutor' => $contract->tutor_person_id === null ? null : ['id' => $contract->tutor_person_id, 'label' => $tutor['label'] ?? 'Personne anonymisée'],
            'starts_on' => $contract->starts_on->toDateString(),
            'ends_on' => $contract->ends_on->toDateString(),
            'opco' => $org($contract->opco_organisation_id),
        ];
    }
}
