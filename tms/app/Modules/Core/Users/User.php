<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Tenancy\TenantContext;
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
use LogicException;
use Spatie\Permission\Traits\HasRoles;

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
 * @property string|null $two_factor_secret encrypted at rest
 * @property list<string>|null $two_factor_recovery_codes sha256 hashes, encrypted at rest
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_step
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasRoles, HasUuids, Notifiable;

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
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_used_step',
        'roles',
        'permissions',
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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
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

    /**
     * Revokes every other session of the user after a security change (2FA
     * enabled, disabled, new recovery codes): rotates the remember token and
     * deletes the database session rows except $keepSessionId.
     */
    public function revokeOtherSessions(?string $keepSessionId): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
            ->delete();
    }

    /**
     * Primary role in the current tenant (one role per user in lot 1a), or
     * null for an account without role (it then has no permission).
     */
    public function role(): ?Role
    {
        /** @var RoleModel|null $record */
        $record = $this->roles->first();

        return $record === null ? null : Role::tryFrom($record->name);
    }

    /**
     * Replaces the user's role. Must run in the user's own tenant (the role
     * records are per tenant). Dispatches PrimaryRoleChanged when it changes.
     */
    public function assignPrimaryRole(Role $role): void
    {
        if ($this->tenant_id !== app(TenantContext::class)->id()) {
            throw new LogicException('Le rôle d\'un utilisateur s\'attribue dans son propre organisme.');
        }

        $this->unsetRelation('roles');
        $previous = $this->role();

        if ($previous === $role) {
            return;
        }

        DB::transaction(fn () => $this->syncRoles([$role->value]));
        $this->unsetRelation('roles');

        PrimaryRoleChanged::dispatch($this, $previous, $role);
    }

    /**
     * Two-factor authentication enrolled and confirmed with a valid code.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
