<?php

use App\Modules\Admissions\OnlineTests\OnlineTest;
use App\Modules\Admissions\OnlineTests\OnlineTests;
use Illuminate\Support\Str;

/* Fixtures of the online tests (needs Support/admissions.php). */

/** A content of two sections: profile, then graded questions (20 points). */
function otContent(): array
{
    return ['sections' => [
        ['title' => 'Mon parcours', 'questions' => [
            ['id' => (string) Str::uuid7(), 'type' => 'yes_no', 'label' => 'Avez-vous été accompagné(e) ?', 'points' => 0, 'required' => true],
            ['id' => (string) Str::uuid7(), 'type' => 'long_text', 'label' => 'Qu\'attendez-vous de la formation ?', 'points' => 0, 'required' => false],
        ]],
        ['title' => 'Mathématiques commerciales', 'questions' => [
            ['id' => 'q-tf', 'type' => 'true_false', 'label' => '1 h 45 min = 1,75 h', 'answer' => true, 'points' => 2, 'required' => true],
            ['id' => 'q-num', 'type' => 'number', 'label' => '14 crayons à 2,75 €', 'answer' => '38.50', 'tolerance' => '0.01', 'points' => 3, 'required' => true],
            ['id' => 'q-one', 'type' => 'single_choice', 'label' => 'Durée de référence des congés payés', 'choices' => [['id' => 'a', 'label' => '30 jours ouvrables'], ['id' => 'b', 'label' => '30 jours ouvrés']], 'answer' => 'a', 'points' => 2, 'required' => true],
            ['id' => 'q-many', 'type' => 'multiple_choice', 'label' => 'Charges', 'choices' => [['id' => 'x', 'label' => 'Loyer'], ['id' => 'y', 'label' => 'Ventes'], ['id' => 'z', 'label' => 'Salaires']], 'answer' => ['x', 'z'], 'points' => 3, 'required' => false],
            ['id' => 'q-open', 'type' => 'short_text', 'label' => 'Que signifie TVA ?', 'points' => 10, 'required' => true],
        ]],
    ]];
}

/** A published test of the DAS of « CAP Cuisine » (version 1). */
function otPublished(array $attributes = []): OnlineTest
{
    $tests = app(OnlineTests::class);
    $test = $tests->create(['title' => 'Test tertiaire', 'das_ids' => [adTraining()->das_id], 'training_ids' => [], 'time_limit_minutes' => 60, 'content' => otContent(), ...$attributes], admin());
    $tests->publish($test, admin());

    return $test->refresh();
}
