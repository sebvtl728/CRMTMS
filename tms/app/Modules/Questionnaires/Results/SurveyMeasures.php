<?php

namespace App\Modules\Questionnaires\Results;

use App\Modules\Qualite\Public\Measures;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use Carbon\CarbonImmutable;

/**
 * Qualiopi indicators 30 (« recueil des appréciations ») and 2 (published
 * results): response and satisfaction rates of the hot and cold
 * questionnaires of the trainees, and of the entreprise one when used, over
 * the sessions ended in the last 12 months (Paris days).
 */
final class SurveyMeasures
{
    public const MIN_RESPONSE_RATE = 50;

    public static function register(): void
    {
        foreach ([30, 2] as $indicator) {
            Measures::register($indicator, 'questionnaires.hot.response', fn () => self::response('hot', 'trainee', 'Taux de réponse à chaud (stagiaires)'));
            Measures::register($indicator, 'questionnaires.hot.satisfaction', fn () => self::satisfaction('hot', 'trainee', 'Taux de satisfaction à chaud (stagiaires)'));
            Measures::register($indicator, 'questionnaires.cold.response', fn () => self::response('cold', 'trainee', 'Taux de réponse à froid (stagiaires)'));
            Measures::register($indicator, 'questionnaires.cold.satisfaction', fn () => self::satisfaction('cold', 'trainee', 'Taux de satisfaction à froid (stagiaires)'));
            Measures::register($indicator, 'questionnaires.client.satisfaction', fn () => self::client());
        }
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private static function totals(string $kind, string $audience): array
    {
        $from = CarbonImmutable::now('Europe/Paris')->subYear();
        $totals = SurveyOverview::totals(SurveyOverview::rows(['from' => $from->toDateString(), 'kind' => $kind, 'audience' => $audience]));

        return [$totals, ' (sessions terminées depuis le '.$from->format('d/m/Y').')'];
    }

    /** @return array<string, mixed> */
    public static function response(string $kind, string $audience, string $label): array
    {
        [$t, $since] = self::totals($kind, $audience);
        $rate = $t['response_rate'];

        return [
            'label' => $label.' - 12 derniers mois',
            'value' => $rate === null ? '-' : "{$rate} %",
            'detail' => $t['invited'] === 0 ? 'Aucun questionnaire envoyé'.$since
                : sprintf('%d réponse%s sur %d invitation%s%s', $t['answered'], $t['answered'] > 1 ? 's' : '', $t['invited'], $t['invited'] > 1 ? 's' : '', $since),
            'tone' => $rate === null || $rate < self::MIN_RESPONSE_RATE ? 'warn' : 'ok',
        ];
    }

    /** @return array<string, mixed> */
    public static function satisfaction(string $kind, string $audience, string $label): array
    {
        [$t, $since] = self::totals($kind, $audience);
        $rate = $t['satisfaction_rate'];

        return [
            'label' => $label.' - 12 derniers mois',
            'value' => $rate === null ? '-' : "{$rate} %",
            'detail' => $t['scored'] === 0 ? 'Aucune réponse'.$since
                : sprintf('Moyenne %s · %d répondant%s satisfait%s (≥ 4/5) sur %d%s', SurveyScoring::label($t['average']), $t['satisfied'],
                    $t['satisfied'] > 1 ? 's' : '', $t['satisfied'] > 1 ? 's' : '', $t['scored'], $since),
            'tone' => $rate === null ? 'warn' : 'neutral',
        ];
    }

    /**
     * The entreprise questionnaire, once used; a measure that throws is shown
     * « indisponible », so an unused one answers a neutral « - ».
     *
     * @return array<string, mixed>
     */
    public static function client(): array
    {
        if (! SurveyCampaign::query()->where('audience', SurveyCampaign::CLIENT)->exists()) {
            return ['label' => 'Satisfaction des entreprises', 'value' => '-', 'detail' => 'Questionnaire entreprise non utilisé.', 'tone' => 'neutral'];
        }

        return self::satisfaction('hot', 'client', 'Taux de satisfaction des entreprises');
    }
}
