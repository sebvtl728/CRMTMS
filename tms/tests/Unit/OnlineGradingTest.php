<?php

use App\Modules\Admissions\OnlineTests\OnlineGrading;

$q = fn (string $type, mixed $answer, int $points = 2, array $extra = []) => ['id' => 'q', 'type' => $type, 'answer' => $answer, 'points' => $points, ...$extra];

it('grades every closed question type, all or nothing', function () use ($q) {
    expect(OnlineGrading::points($q('true_false', true), true))->toBe(2)
        ->and(OnlineGrading::points($q('true_false', true), false))->toBe(0)
        ->and(OnlineGrading::points($q('single_choice', 'a'), 'a'))->toBe(2)
        ->and(OnlineGrading::points($q('single_choice', 'a'), 'b'))->toBe(0)
        ->and(OnlineGrading::points($q('multiple_choice', ['x', 'z']), ['z', 'x']))->toBe(2)
        ->and(OnlineGrading::points($q('multiple_choice', ['x', 'z']), ['x']))->toBe(0)
        ->and(OnlineGrading::points($q('number', '38.5', 3, ['tolerance' => '0.01']), '38,50'))->toBe(3)
        ->and(OnlineGrading::points($q('number', '38.5', 3, ['tolerance' => '0.01']), '38.52'))->toBe(0)
        ->and(OnlineGrading::points($q('number', '38.5', 3), 'trente-huit'))->toBe(0)
        ->and(OnlineGrading::points($q('number', '38.5', 3), null))->toBe(0)
        ->and(OnlineGrading::points($q('short_text', null, 5), 'TVA'))->toBeNull()
        ->and(OnlineGrading::points($q('single_choice', null, 0), 'a'))->toBeNull();
});

it('leaves the hand-graded questions pending until each has a score', function () use ($q) {
    $content = ['sections' => [['title' => 'S', 'questions' => [
        [...$q('true_false', true), 'id' => 'a'], ['id' => 'b', 'type' => 'long_text', 'answer' => null, 'points' => 10], ['id' => 'c', 'type' => 'yes_no', 'answer' => null, 'points' => 0],
    ]]]];

    expect(OnlineGrading::grade($content, ['a' => true], []))->toBe(['auto' => 2, 'pending' => ['b'], 'score' => null])
        ->and(OnlineGrading::grade($content, ['a' => true], ['b' => 7]))->toBe(['auto' => 2, 'pending' => [], 'score' => 9]);
});
