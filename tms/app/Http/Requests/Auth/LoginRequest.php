<?php

namespace App\Http\Requests\Auth;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Checks the request's credentials WITHOUT logging in, and returns the
     * matching user: the controller either logs in or, when 2FA is confirmed,
     * starts the two-factor challenge.
     *
     * Only an active, activated account of the request's tenant can match:
     * explicit tenant filter here, plus the tenant scope, RLS on users and
     * ActiveUserProvider (deactivated_at is null, password set).
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        $tenantId = app(TenantContext::class)->id();
        $credentials = [
            'email' => mb_strtolower($this->string('email')->trim()->value()),
            'password' => $this->string('password')->value(),
            fn (Builder $query) => $query->where('tenant_id', $tenantId)->whereNull('deactivated_at'),
        ];

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $user = $guard->validate($credentials) ? $guard->getLastAttempted() : null;

        if (! $user instanceof User) {
            RateLimiter::hit($this->throttleKey());

            // Unknown e-mail, wrong password or deactivated account: journaled
            // with the attempted e-mail only. Not Auth::attempt, so Laravel
            // would not fire Failed itself.
            $known = User::query()->where('email', $credentials['email'])->first();
            event(new Failed('web', $known, ['email' => $credentials['email']]));

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($user, ['password' => $credentials['password']]);
        }

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        $tenant = app(TenantContext::class)->current()->id ?? 'none';

        return Str::transliterate($tenant.'|'.Str::lower($this->string('email')).'|'.$this->ip());
    }
}
