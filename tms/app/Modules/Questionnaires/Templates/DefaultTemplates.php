<?php

namespace App\Modules\Questionnaires\Templates;

use App\Modules\Questionnaires\Surveys\SurveyContent;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use Illuminate\Support\Facades\DB;

/**
 * The three templates an organisme starts with (hot and cold for the
 * trainees, active; hot for the entreprise, inactive), created the first
 * time the questionnaires are used, never again once the organisme has had
 * a template (even deleted).
 */
final class DefaultTemplates
{
    public static function ensure(): void
    {
        if (SurveyTemplate::withTrashed()->exists()) {
            return;
        }
        DB::transaction(function (): void {
            // Two first uses at once: the second sees the rows of the first.
            if (SurveyTemplate::withTrashed()->lockForUpdate()->exists()) {
                return;
            }
            foreach (self::definitions() as $definition) {
                SurveyTemplate::query()->create([
                    ...$definition,
                    'questions' => SurveyContent::validate($definition['questions']),
                    'is_default' => true,
                ]);
            }
        });
    }

    /** @return list<array{name: string, kind: string, audience: string, active: bool, questions: list<array<string, mixed>>}> */
    public static function definitions(): array
    {
        $rating = fn (string $id, string $label) => ['id' => $id, 'type' => 'rating', 'label' => $label, 'required' => true];

        return [
            [
                'name' => 'À chaud — stagiaires', 'kind' => 'hot', 'audience' => 'trainee', 'active' => true,
                'questions' => [
                    $rating('global', 'Quelle est votre satisfaction globale sur cette formation ?'),
                    $rating('objectifs', 'Les objectifs de la formation ont-ils été atteints ?'),
                    $rating('animation', 'Comment évaluez-vous la qualité de l\'animation du formateur ?'),
                    $rating('supports', 'Comment évaluez-vous les supports et moyens pédagogiques ?'),
                    $rating('organisation', 'Comment évaluez-vous l\'organisation et l\'accueil ?'),
                    ['id' => 'recommande', 'type' => 'choice', 'label' => 'Recommanderiez-vous cette formation ?', 'required' => true, 'options' => ['Oui', 'Non']],
                    ['id' => 'commentaires', 'type' => 'text', 'label' => 'Vos commentaires et suggestions', 'required' => false],
                ],
            ],
            [
                'name' => 'À froid — stagiaires', 'kind' => 'cold', 'audience' => 'trainee', 'active' => true,
                'questions' => [
                    $rating('pratique', 'Avez-vous pu mettre en pratique les acquis de la formation ?'),
                    $rating('impact', 'La formation a-t-elle eu un effet positif sur votre activité ?'),
                    $rating('recul', 'Avec le recul, quelle est votre satisfaction sur cette formation ?'),
                    ['id' => 'besoins', 'type' => 'text', 'label' => 'Avez-vous de nouveaux besoins de formation ?', 'required' => false],
                ],
            ],
            [
                'name' => 'À chaud — entreprise', 'kind' => 'hot', 'audience' => 'client', 'active' => false,
                'questions' => [
                    $rating('global', 'Quelle est votre satisfaction globale sur cette formation ?'),
                    $rating('besoin', 'La formation a-t-elle répondu au besoin de votre entreprise ?'),
                    $rating('administratif', 'Comment évaluez-vous l\'organisation administrative ?'),
                    ['id' => 'commentaires', 'type' => 'text', 'label' => 'Vos commentaires et suggestions', 'required' => false],
                ],
            ],
        ];
    }
}
