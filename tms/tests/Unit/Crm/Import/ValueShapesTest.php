<?php

use App\Modules\Crm\Import\ValueShapes;

it('recognises the shape of a cell', function (string $value, ?string $shape) {
    expect(ValueShapes::of($value))->toBe($shape);
})->with([
    'e-mail' => ['lea.durand@example.test', 'email'],
    'not an e-mail' => ['lea.durand@', null],
    'mobile' => ['06 12 34 56 78', 'mobile'],
    'international mobile' => ['+33 6 12 34 56 78', 'mobile'],
    'mobile with dots' => ['07.12.34.56.78', 'mobile'],
    'too short for a mobile' => ['06 12 34 56', null],
    'landline' => ['01 23 45 67 89', 'phone'],
    'international landline' => ['+33 (0)1 23 45 67 89', 'phone'],
    'not a landline' => ['00 23 45 67 89', null],
    'postcode' => ['75011', 'postcode'],
    'four digits are no postcode' => ['7501', null],
    'siret' => ['732 829 320 00074', 'siret'],
    'siret with a wrong key' => ['73282932000075', null],
    'siren' => ['732829320', 'siren'],
    'siren with a wrong key' => ['732829321', null],
    'french date' => ['16/11/2026', 'date'],
    'iso date' => ['2026-11-16', 'date'],
    'impossible date' => ['31/02/2026', null],
    'website' => ['www.exemple.fr', 'website'],
    'website with a scheme' => ['https://exemple.fr', 'website'],
    'a name with a dot is no website' => ['jean.dupont', null],
    'civility M.' => ['M.', 'civility'],
    'civility Mme' => ['Mme', 'civility'],
    'civility Madame' => ['Madame', 'civility'],
    'not a civility' => ['Martin', null],
    'empty' => ['  ', null],
]);
