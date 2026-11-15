<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\Auditable;
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
 * @property bool $daily_digest opt-in to the daily summary of the actions to do
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * Recorded as "[masqué]" in the audit journal.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /**
     * Dropped from the audit journal: last_login_at is covered by auth.login,
     * the others are rotated/bumped mechanically (logout, replay protection).
     *
     * @var list<string>
     */
    protected array $auditIgnore = ['last_login_at', 'remember_token', 'two_factor_last_used_step'];

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
            'daily_digest' => 'boolean',
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
     * Lifts the block. Sessions and "remember me" cookies revoked at
     * deactivation stay revoked and two-factor settings are untouched: the
     * user signs in again.
     */
    public function reactivate(): void
    {
        $this->forceFill(['deactivated_at' => null])->save();
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
     * Primary role in the current tenant (one role per user), or null for an
     * account without role (it then has no permission).
     */
    public function role(): ?RoleModel
    {
        $record = $this->roles->first();

        return $record instanceof RoleModel ? $record : null;
    }

    /**
     * Replaces the user's role (a system role by its enum, or any role of the
     * organisme). Must run in the user's own tenant (the role records are per
     * tenant). Dispatches PrimaryRoleChanged when it changes.
     */
    public function assignPrimaryRole(RoleModel|Role $role): void
    {
        if ($this->tenant_id !== app(TenantContext::class)->id()) {
            throw new LogicException('Le rôle d\'un utilisateur s\'attribue dans son propre organisme.');
        }
        if ($role instanceof Role) {
            $role = RoleModel::forSystem($role);
        }
        if ($role->tenant_id !== $this->tenant_id) {
            throw new LogicException('Le rôle d\'un utilisateur s\'attribue dans son propre organisme.');
        }

        $this->unsetRelation('roles');
        $previous = $this->role();

        if ($previous?->is($role) === true) {
            return;
        }

        DB::transaction(fn () => $this->syncRoles([$role]));
        $this->unsetRelation('roles');
        Scopes::forget();

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
