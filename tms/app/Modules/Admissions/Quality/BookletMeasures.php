<?php

namespace App\Modules\Admissions\Quality;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Booklet\BookletGrid;
use App\Modules\Admissions\Booklet\BookletMonth;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Booklet\BookletVisit;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Qualite\Public\Measures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Qualiopi measures of the apprenticeship booklet (spec 2026-11-24, B3):
 * indicator 11 (skills evaluated on the centre side, apprentices whose
 * contract runs today), indicator 28 (company pages of the past months
 * signed by the tutor, online or on paper; company visits over 12 months).
 */
final class BookletMeasures
{
    public static function register(): void
    {
        Measures::register(11, 'admissions.booklet_skills', fn () => self::skills());
        Measures::register(28, 'admissions.booklet_tutor', fn () => self::tutorPages());
        Measures::register(28, 'admissions.booklet_visits', fn () => self::visits());
    }

    /** @return array<string, mixed> */
    public static function skills(): array
    {
        $grid = app(BookletGrid::class);
        $done = 0;
        $total = 0;
        foreach (self::current() as $candidature) {
            foreach ($grid->tree($candidature)['types'] as $type) {
                foreach ($type['competences'] as $competence) {
                    foreach ($competence['skills'] as $skill) {
                        $total++;
                        $done += $skill['centre'] === null ? 0 : 1;
                    }
                }
            }
        }

        return [
            'label' => 'Savoir-faire évalués par le centre (apprentis en cours)',
            ...Measures::ratio($done, $total),
            'detail' => 'Grille de compétences du livret d\'apprentissage, colonne centre.',
        ];
    }

    /** @return array<string, mixed> */
    public static function tutorPages(): array
    {
        $current = BookletMonths::today()->startOfMonth()->format('Y-m');
        $done = 0;
        $total = 0;
        foreach (self::current() as $candidature) {
            foreach (BookletMonths::months($candidature) as $month) {
                if ($month['month'] >= $current) {
                    continue;
                }
                $total++;
                $done += in_array($month['pages'][BookletMonth::COMPANY]['tutor']['status'] ?? null, ['signed', 'paper'], true) ? 1 : 0;
            }
        }

        return [
            'label' => 'Pages entreprise des mois écoulés signées par le tuteur (apprentis en cours)',
            ...Measures::ratio($done, $total),
            'detail' => 'Livret d\'apprentissage : signature en ligne ou sur papier.',
        ];
    }

    /** @return array<string, mixed> */
    public static function visits(): array
    {
        $today = BookletMonths::today();
        $from = $today->subYear();
        $ids = AlternanceContract::query()->where('ends_on', '>=', $from->toDateString())->where('starts_on', '<=', $today->toDateString())
            ->whereIn('candidature_id', Candidature::query()->whereNull('anonymised_at')->select('id'))->pluck('candidature_id');
        $visits = BookletVisit::query()->whereIn('candidature_id', $ids)->where('visited_on', '>=', $from->toDateString())->count();
        $apprentices = $ids->count();

        return [
            'label' => 'Visites en entreprise (12 derniers mois)',
            'value' => $visits.' visite'.($visits > 1 ? 's' : '').' pour '.$apprentices.' apprenti'.($apprentices > 1 ? 's' : ''),
            'tone' => $apprentices === 0 ? 'neutral' : ($visits < $apprentices ? 'warn' : 'ok'),
            'detail' => 'Depuis le '.$from->format('d/m/Y').', apprentis dont le contrat couvre la période.',
        ];
    }

    /**
     * Candidatures whose alternance contract runs today (Paris), not anonymised.
     *
     * @return Collection<int, Candidature>
     */
    private static function current(): Collection
    {
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();

        return Candidature::query()->whereNull('anonymised_at')
            ->whereIn('id', AlternanceContract::query()->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->select('candidature_id'))
            ->get();
    }
}
