<?php

namespace Database\Factories;

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\Companies\VatRegime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'legal_form' => LegalForm::Sas,
            'scop_underlying_form' => null,
            'capital_amount' => '10000.00',
            'capital_variable' => false,
            'siret' => self::siret(),
            'rcs_city' => fake()->city(),
            'vat_number' => null,
            'vat_regime' => VatRegime::Subject,
            'nda_number' => null,
            'address_line1' => fake()->streetAddress(),
            'address_postal_code' => fake()->numerify('#####'),
            'address_city' => fake()->city(),
            'address_country' => 'FR',
            'email' => fake()->safeEmail(),
            'phone' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    /**
     * A random SIRET with a valid Luhn key.
     */
    public static function siret(): string
    {
        $base = fake()->numerify('#############'); // 13 digits
        for ($key = 0; $key <= 9; $key++) {
            $sum = 0;
            foreach (str_split(strrev($base.$key)) as $i => $char) {
                $d = (int) $char;
                if ($i % 2 === 1) {
                    $d = $d * 2 > 9 ? $d * 2 - 9 : $d * 2;
                }
                $sum += $d;
            }
            if ($sum % 10 === 0) {
                return $base.$key;
            }
        }

        return $base.'0'; // unreachable: exactly one key in 0..9 satisfies Luhn
    }
}
