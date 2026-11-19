<?php

namespace App\Modules\Admissions\Quality;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Qualite\Public\Measures;
use Carbon\CarbonImmutable;

/**
 * Qualiopi indicators 8 (positioning test received, candidatures of the last
 * 12 months, withdrawn left out) and 13 (alternance contracts with a tutor).
 */
final class AdmissionsMeasures
{
    public static function register(): void
    {
        Measures::register(8, 'admissions.positioning', fn () => self::positioning());
        Measures::register(13, 'admissions.tutors', fn () => self::tutors());
    }

    /** @return array<string, mixed> */
    public static function positioning(): array
    {
        $from = CarbonImmutable::now('Europe/Paris')->startOfDay()->subYear();
        $query = Candidature::query()->where('created_at', '>=', $from)->where('status', '!=', CandidatureStatus::Withdrawn->value);
        $total = (clone $query)->count();
        $received = (clone $query)->whereNotNull('positioning_received_on')->count();

        return [
            'label' => 'Candidatures avec test de positionnement reçu (12 derniers mois)',
            ...Measures::ratio($received, $total),
            'detail' => 'Candidatures créées depuis le '.$from->format('d/m/Y').', désistements exclus.',
        ];
    }

    /** @return array<string, mixed> */
    public static function tutors(): array
    {
        $query = AlternanceContract::query()->whereIn('candidature_id', Candidature::query()->select('id'));
        $total = (clone $query)->count();
        $withTutor = (clone $query)->whereNotNull('tutor_person_id')->count();

        return [
            'label' => 'Contrats d\'alternance avec tuteur désigné',
            ...Measures::ratio($withTutor, $total),
            'detail' => null,
        ];
    }
}
