<?php

namespace App\Modules\Admissions\Gdpr;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use Carbon\CarbonImmutable;

/**
 * Retention of the candidatures that did not lead to a training: a rejected
 * or withdrawn candidature is anonymised two years after its last change
 * (stage, entry, file or contract: each touches it). The CRM person keeps
 * her own retention rule.
 */
final class PurgeStaleCandidatures
{
    public const YEARS = 2;

    /** @return int the candidatures anonymised in the current organisme */
    public function run(): int
    {
        $threshold = CarbonImmutable::now('Europe/Paris')->subYears(self::YEARS);
        $count = 0;
        Candidature::withTrashed()
            ->whereIn('status', [CandidatureStatus::Rejected->value, CandidatureStatus::Withdrawn->value])
            ->whereNull('anonymised_at')
            ->where('updated_at', '<', $threshold)
            ->orderBy('id')
            ->chunkById(200, function ($candidatures) use (&$count): void {
                foreach ($candidatures as $candidature) {
                    app(CandidatureAnonymisation::class)->anonymise($candidature);
                    $count++;
                }
            });

        return $count;
    }
}
