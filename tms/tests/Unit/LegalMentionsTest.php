<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\Companies\LegalMentions;
use App\Rules\Siret;

// Numbers are printed with U+00A0 (no-break space) as thousands separator so a
// SIREN or an amount is never split across lines: the expectations spell it out.
const NB = "\u{00A0}";

it('builds SCOP legal mentions with variable capital', function () {
    $c = Company::factory()->make([
        'name' => 'CAP FORMATION', 'legal_form' => LegalForm::Scop,
        'scop_underlying_form' => 'sarl', 'capital_variable' => true, 'capital_amount' => null,
        'siret' => '73282932000074', 'rcs_city' => 'Lyon',
    ]);

    expect(LegalMentions::for($c))->toBe('CAP FORMATION – SCOP SARL à capital variable – RCS Lyon 732'.NB.'829'.NB.'320 – SIRET 732'.NB.'829'.NB.'320'.NB.'00074');
});

it('builds SAS mentions with fixed capital', function () {
    $c = Company::factory()->make([
        'name' => 'ACME', 'legal_form' => LegalForm::Sas, 'scop_underlying_form' => null,
        'capital_variable' => false, 'capital_amount' => '10000', 'siret' => '12345678900015', 'rcs_city' => 'Paris',
    ]);

    expect(LegalMentions::for($c))->toBe('ACME – SAS au capital de 10'.NB.'000,00'.NB.'€ – RCS Paris 123'.NB.'456'.NB.'789 – SIRET 123'.NB.'456'.NB.'789'.NB.'00015');
});

it('prints a variable capital amount when one is set', function () {
    $c = Company::factory()->make([
        'name' => 'COOP', 'legal_form' => LegalForm::Scic, 'scop_underlying_form' => 'sas',
        'capital_variable' => true, 'capital_amount' => '1500.5', 'siret' => null, 'rcs_city' => null,
    ]);

    expect(LegalMentions::for($c))->toBe('COOP – SCIC SAS à capital variable de 1'.NB.'500,50'.NB.'€');
});

it('degrades gracefully without siret or rcs', function () {
    $placeholder = Company::factory()->make([
        'name' => 'CAP FORMATION', 'legal_form' => LegalForm::Autre, 'scop_underlying_form' => null,
        'capital_variable' => false, 'capital_amount' => null, 'siret' => null, 'rcs_city' => null,
    ]);
    expect(LegalMentions::for($placeholder))->toBe('CAP FORMATION – Autre');

    $noRcs = Company::factory()->make([
        'name' => 'ASSO', 'legal_form' => LegalForm::Association, 'capital_amount' => null, 'capital_variable' => false,
        'siret' => '73282932000074', 'rcs_city' => null,
    ]);
    expect(LegalMentions::for($noRcs))->toBe('ASSO – Association – SIRET 732'.NB.'829'.NB.'320'.NB.'00074');
});

it('has the VAT exemption notice', function () {
    expect(LegalMentions::vatExemptionNotice())->toBe('TVA non applicable, art. 261-4-4° a du CGI');
});

it('labels the legal forms', function () {
    expect(LegalForm::Scop->label())->toBe('SCOP')
        ->and(LegalForm::Collectivite->label())->toBe('Collectivité territoriale')
        ->and(LegalForm::EtablissementPublic->label())->toBe('Établissement public')
        ->and(LegalForm::Autre->label())->toBe('Autre')
        ->and(LegalForm::cases())->toHaveCount(12);
});

it('validates sirets with the Luhn check', function (string $siret, bool $valid) {
    $failed = false;
    (new Siret)->validate('siret', $siret, function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBe(! $valid);
})->with([
    ['73282932000074', true],
    ['12345678900015', true],
    ['12345678901234', false],
    ['7328293200007', false],
    ['732829320000745', false],
    ['7328293200007A', false],
]);
