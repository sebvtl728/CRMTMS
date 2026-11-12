<?php

namespace App\Modules\Connectors\Sirene;

/**
 * INSEE code tables the directory answers with: legal category
 * ("nature_juridique") and employee range ("tranche_effectif_salarie").
 * The legal-form labels are the ones of the LegalForm vocabulary; a category
 * with no clear equivalent returns null (the field stays empty).
 */
final class InseeCodes
{
    /** @var array<int|string, array{0: string, 1: int, 2: ?int}> code => [label, min, max] */
    private const EMPLOYEES = [
        'NN' => ['Unité non employeuse', 0, 0],
        '00' => ['0 salarié', 0, 0],
        '01' => ['1 ou 2 salariés', 1, 2],
        '02' => ['3 à 5 salariés', 3, 5],
        '03' => ['6 à 9 salariés', 6, 9],
        '11' => ['10 à 19 salariés', 10, 19],
        '12' => ['20 à 49 salariés', 20, 49],
        '21' => ['50 à 99 salariés', 50, 99],
        '22' => ['100 à 199 salariés', 100, 199],
        '31' => ['200 à 249 salariés', 200, 249],
        '32' => ['250 à 499 salariés', 250, 499],
        '41' => ['500 à 999 salariés', 500, 999],
        '42' => ['1 000 à 1 999 salariés', 1000, 1999],
        '51' => ['2 000 à 4 999 salariés', 2000, 4999],
        '52' => ['5 000 à 9 999 salariés', 5000, 9999],
        '53' => ['10 000 salariés et plus', 10000, null],
    ];

    public static function legalFormLabel(?string $code): ?string
    {
        if ($code === null || preg_match('/^\d{4}$/', $code) !== 1) {
            return null;
        }

        return match (true) {
            $code[0] === '1' => 'EI',
            in_array($code, ['5458', '5558', '5658'], true) => 'SCOP',
            in_array($code, ['5410', '5498'], true) => 'EURL',
            str_starts_with($code, '54') => 'SARL',
            $code === '5710' => 'SAS',
            $code === '5720' => 'SASU',
            str_starts_with($code, '55'), str_starts_with($code, '56') => 'SA',
            str_starts_with($code, '92') => 'Association',
            str_starts_with($code, '72'), str_starts_with($code, '73') => 'Collectivité territoriale',
            str_starts_with($code, '74'), str_starts_with($code, '75') => 'Établissement public',
            default => null,
        };
    }

    /**
     * @return array{label: string, min: int, max: ?int}|null
     */
    public static function employeeRange(?string $code): ?array
    {
        if ($code === null || ! isset(self::EMPLOYEES[$code])) {
            return null;
        }
        [$label, $min, $max] = self::EMPLOYEES[$code];

        return ['label' => $label, 'min' => $min, 'max' => $max];
    }
}
