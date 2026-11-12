<?php

use App\Modules\Crm\People\PhoneNumber;

it('normalises French numbers to E.164', function (string $input, ?string $expected) {
    expect(PhoneNumber::normalise($input))->toBe($expected);
})->with([
    ['0612345678', '+33612345678'],
    ['06 12 34 56 78', '+33612345678'],
    ['06.12.34.56.78', '+33612345678'],
    ['06-12-34-56-78', '+33612345678'],
    ['+33 6 12 34 56 78', '+33612345678'],
    ['+33 (0)6 12 34 56 78', '+33612345678'],
    ['0033612345678', '+33612345678'],
    ['04 72 00 11 22', '+33472001122'],
    ['+41 22 123 45 67', '+41221234567'],
    ['  ', null],
    ['', null],
]);

it('keeps what it does not recognise', function (string $input) {
    expect(PhoneNumber::normalise($input))->toBe($input);
})->with(['poste 12', '12345', '06 12 34', '0012']);
