<?php

namespace Database\Factories;

use App\Modules\Core\Access\Role;
use App\Modules\Core\Users\TwoFactorAuthenticator;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Plain recovery codes of the last user built with withTwoFactor().
     *
     * @var list<string>
     */
    public static array $lastRecoveryCodes = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Account invited but not activated yet (no password).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Primary role, assigned after creation (needs the user's tenant as the
     * current tenant context, as every user creation does).
     */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignPrimaryRole($role));
    }

    /**
     * Confirmed two-factor authentication, with 8 recovery codes (plain codes
     * in UserFactory::$lastRecoveryCodes).
     */
    public function withTwoFactor(): static
    {
        return $this->state(function () {
            self::$lastRecoveryCodes = array_map(fn () => Str::lower(Str::random(10).'-'.Str::random(10)), range(1, TwoFactorAuthenticator::RECOVERY_CODES));

            return [
                'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
                'two_factor_recovery_codes' => array_map(TwoFactorAuthenticator::hashRecoveryCode(...), self::$lastRecoveryCodes),
                'two_factor_confirmed_at' => now(),
            ];
        });
    }
}
