<?php

namespace App\Modules\Admissions\Crm;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * What the module keeps on the CRM fiches (Crm\Public\FicheLinks): an import
 * undo keeps a fiche a candidature or a contract names; a merge repoints
 * them to the kept fiche; the RGPD retention holds a person whose candidature
 * changed within the period.
 */
final class CandidatureLinks
{
    public static function linked(string $type, string $id): bool
    {
        if ($type === 'person') {
            return Candidature::withTrashed()->where('person_id', $id)->exists()
                || AlternanceContract::query()->where('tutor_person_id', $id)->exists();
        }

        return Candidature::withTrashed()->where('prescriber_organisation_id', $id)->exists()
            || AlternanceContract::query()->where(fn ($q) => $q->where('employer_organisation_id', $id)->orWhere('opco_organisation_id', $id))->exists();
    }

    public static function merge(string $type, string $keepId, string $dropId): void
    {
        if ($type === 'person') {
            Candidature::withTrashed()->where('person_id', $dropId)->update(['person_id' => $keepId]);
            AlternanceContract::query()->where('tutor_person_id', $dropId)->update(['tutor_person_id' => $keepId]);

            return;
        }
        Candidature::withTrashed()->where('prescriber_organisation_id', $dropId)->update(['prescriber_organisation_id' => $keepId]);
        AlternanceContract::query()->where('employer_organisation_id', $dropId)->update(['employer_organisation_id' => $keepId]);
        AlternanceContract::query()->where('opco_organisation_id', $dropId)->update(['opco_organisation_id' => $keepId]);
    }

    /**
     * People whose candidature changed after $threshold (a column person_id).
     */
    public static function retentionHold(CarbonImmutable $threshold): Builder
    {
        return Candidature::withTrashed()->where('updated_at', '>=', $threshold)->select('person_id')->toBase();
    }
}
