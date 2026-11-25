<?php

namespace App\Modules\Admissions\Alternance;

use App\Modules\Admissions\Candidatures\Candidature;
use Illuminate\Support\Str;

/**
 * The alternance contract of a dossier, for the paperwork of the other
 * modules (Sessions\Public\Paperwork::registerAlternance): the contract of
 * the candidature (out of the trash) whose dossier it is.
 */
final class AlternanceFacts
{
    /**
     * @return array{contract_type: string, starts_on: string, ends_on: string, apprentice_person_id: string, employer_organisation_id: string, tutor_person_id: ?string, opco_organisation_id: ?string, rncp_code: ?string, training_id: ?string}|null
     */
    public static function forDossier(string $dossierId): ?array
    {
        if (! Str::isUuid($dossierId)) {
            return null;
        }
        $candidature = Candidature::query()->where('dossier_id', $dossierId)->orderByDesc('created_at')->first();
        $contract = $candidature === null ? null : AlternanceContract::query()->where('candidature_id', $candidature->id)->first();

        return $candidature === null || $contract === null ? null : self::of($candidature, $contract);
    }

    /**
     * @return array{contract_type: string, starts_on: string, ends_on: string, apprentice_person_id: string, employer_organisation_id: string, tutor_person_id: ?string, opco_organisation_id: ?string, rncp_code: ?string, training_id: ?string}
     */
    public static function of(Candidature $candidature, AlternanceContract $contract): array
    {
        return [
            'contract_type' => $contract->contract_type->value,
            'starts_on' => $contract->starts_on->toDateString(),
            'ends_on' => $contract->ends_on->toDateString(),
            'apprentice_person_id' => $candidature->person_id,
            'employer_organisation_id' => $contract->employer_organisation_id,
            'tutor_person_id' => $contract->tutor_person_id,
            'opco_organisation_id' => $contract->opco_organisation_id,
            'rncp_code' => $candidature->rncp_code,
            'training_id' => $candidature->training_id,
        ];
    }
}
