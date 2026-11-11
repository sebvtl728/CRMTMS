<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An account of one organisme (tenant). The same e-mail may exist in several
 * tenants; authentication only ever sees the users of the request's tenant
 * (Eloquent scope + PostgreSQL RLS).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_platform_admin
 * @property string|null $remember_token
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deactivated_at
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasUuids, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ExternalIdentity, $this>
     */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Blocks the account and revokes its sessions:
     *  - the user provider ignores deactivated users (AppServiceProvider), so
     *    any session or "remember me" cookie stops authenticating immediately,
     *    whatever the session driver;
     *  - the remember token is rotated;
     *  - the rows of the database session store are deleted.
     */
    public function deactivate(): void
    {
        $this->forceFill([
            'deactivated_at' => CarbonImmutable::now(),
            'remember_token' => Str::random(60),
        ])->save();

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->delete();
    }
}
