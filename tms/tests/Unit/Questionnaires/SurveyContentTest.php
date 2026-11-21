<?php

use App\Modules\Questionnaires\Surveys\SurveyContent;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use Illuminate\Validation\ValidationException;

function surveyQuestions(): array
{
    return SurveyContent::validate([
        ['id' => 'q1', 'type' => 'rating', 'label' => 'Satisfaction globale', 'required' => true],
        ['id' => 'q2', 'type' => 'rating', 'label' => 'Animation', 'required' => false],
        ['id' => 'q3', 'type' => 'choice', 'label' => 'Recommanderiez-vous ?', 'required' => true, 'options' => ['Oui', 'Non']],
        ['id' => 'q4', 'type' => 'text', 'label' => 'Commentaires', 'required' => false],
    ]);
}

function surveyErrors(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('keeps valid questions and gives an id to a new one', function () {
    $questions = SurveyContent::validate([['type' => 'text', 'label' => '  Un avis ?  ', 'required' => false]]);

    expect($questions[0]['id'])->toMatch('/^[a-z0-9]{8}$/')
        ->and($questions[0])->toMatchArray(['type' => 'text', 'label' => 'Un avis ?', 'required' => false])
        ->and(surveyQuestions())->toHaveCount(4);
});

it('refuses an empty questionnaire, more than 30 questions, an unknown type and a choice of one option', function () {
    $one = fn (array $q) => ['id' => null, 'type' => 'rating', 'label' => 'Note', 'required' => true, ...$q];

    expect(surveyErrors(fn () => SurveyContent::validate([])))->toHaveKey('questions')
        ->and(surveyErrors(fn () => SurveyContent::validate(array_fill(0, 31, $one([])))))->toHaveKey('questions')
        ->and(surveyErrors(fn () => SurveyContent::validate([$one(['type' => 'date'])])))->toHaveKey('questions.0.type')
        ->and(surveyErrors(fn () => SurveyContent::validate([$one(['label' => ''])])))->toHaveKey('questions.0.label')
        ->and(surveyErrors(fn () => SurveyContent::validate([$one(['type' => 'choice', 'options' => ['Oui']])])))->toHaveKey('questions.0.options')
        ->and(surveyErrors(fn () => SurveyContent::validate([$one(['type' => 'choice', 'options' => ['Oui', 'Oui']])])))->toHaveKey('questions.0.options')
        ->and(surveyErrors(fn () => SurveyContent::validate([$one(['id' => 'a']), $one(['id' => 'a'])])))->toHaveKey('questions.1.id');
});

it('checks the answers: required, ratings 1 to 5, known options, text length', function () {
    $q = surveyQuestions();

    expect(surveyErrors(fn () => SurveyContent::validateAnswers($q, ['q3' => 'Oui'])))->toBe(['answers.q1' => ['Cette question est obligatoire.']])
        ->and(surveyErrors(fn () => SurveyContent::validateAnswers($q, ['q1' => 0, 'q3' => 'Oui'])))->toHaveKey('answers.q1')
        ->and(surveyErrors(fn () => SurveyContent::validateAnswers($q, ['q1' => 6, 'q3' => 'Oui'])))->toHaveKey('answers.q1')
        ->and(surveyErrors(fn () => SurveyContent::validateAnswers($q, ['q1' => '4.5', 'q3' => 'Oui'])))->toHaveKey('answers.q1')
        ->and(surveyErrors(fn () => SurveyContent::validateAnswers($q, ['q1' => 5, 'q3' => 'Peut-être'])))->toHaveKey('answers.q3')
        ->and(surveyErrors(fn () => SurveyContent::validateAnswers($q, ['q1' => 5, 'q3' => 'Oui', 'q4' => str_repeat('a', 2001)])))->toHaveKey('answers.q4');
});

it('keeps the valid answers only, the ratings as integers', function () {
    expect(SurveyContent::validateAnswers(surveyQuestions(), ['q1' => '5', 'q2' => '', 'q3' => 'Non', 'q4' => '  Très bien  ', 'zz' => 'x']))
        ->toBe(['q1' => 5, 'q3' => 'Non', 'q4' => 'Très bien']);
});

it('scores the mean of the ratings × 100, half up, and labels it', function () {
    $q = surveyQuestions();
    $three = [...$q, ['id' => 'q5', 'type' => 'rating', 'label' => 'Supports', 'required' => false]];

    expect(SurveyScoring::score($three, ['q1' => 5, 'q2' => 4, 'q5' => 4]))->toBe(433)
        ->and(SurveyScoring::score($q, ['q1' => 5, 'q2' => 4]))->toBe(450)
        ->and(SurveyScoring::score($three, ['q1' => 5, 'q2' => 5, 'q5' => 4]))->toBe(467)
        ->and(SurveyScoring::score($q, ['q3' => 'Oui']))->toBeNull()
        ->and(SurveyScoring::label(433))->toBe('4,3/5')
        ->and(SurveyScoring::label(450))->toBe('4,5/5')
        ->and(SurveyScoring::label(500))->toBe('5/5')
        ->and(SurveyScoring::label(null))->toBe('-')
        ->and(SurveyScoring::SATISFIED)->toBe(400);
});
