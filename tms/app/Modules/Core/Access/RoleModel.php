<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role record of one tenant (spatie "team" = tenant). Under RLS: a tenant
 * never sees another tenant's roles.
 *
 *  - system role: named after a Role enum value, never deleted; its label
 *    may be changed (null = the enum's label), except the administrator's;
 *  - custom role (RoleCatalogue): named custom_<uuid v7>, a name never shown.
 *
 * 2FA is always mandatory for admin_organisme and direction, whatever the
 * requires_2fa column says.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $label
 * @property string $guard_name
 * @property bool $requires_2fa
 * @property bool $system
 */
class RoleModel extends SpatieRole
{
    use BelongsToTenant, HasUuids;

    public const CUSTOM_PREFIX = 'custom_';

    protected $table = 'roles';

    protected function casts(): array
    {
        return [
            'requires_2fa' => 'boolean',
            'system' => 'boolean',
        ];
    }

    /**
     * The tenant's record of a system role (current tenant context).
     */
    public static function forSystem(Role $role): self
    {
        return self::query()->where('name', $role->value)->where('guard_name', RoleSeeder::GUARD)->first()
            ?? throw new LogicException("Rôle système absent de l'organisme : {$role->value}.");
    }

    /**
     * A role of the current tenant by its name (enum value or custom_…).
     */
    public static function named(string $name): ?self
    {
        return self::query()->where('name', $name)->where('guard_name', RoleSeeder::GUARD)->first();
    }

    public function systemRole(): ?Role
    {
        return $this->system ? Role::tryFrom($this->name) : null;
    }

    public function isAdmin(): bool
    {
        return $this->systemRole() === Role::AdminOrganisme;
    }

    public function label(): string
    {
        return $this->label ?? $this->systemRole()?->label() ?? 'Rôle personnalisé';
    }

    public function requires2fa(): bool
    {
        return $this->systemRole()?->requires2fa() === true || $this->requires_2fa;
    }

    /**
     * Form compared for label uniqueness: no case, no accent, single spaces.
     */
    public static function normaliseLabel(string $label): string
    {
        return Str::lower(Str::ascii(Str::squish($label)));
    }

    /**
     * System roles in the enum's order, then custom roles by label.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $names = array_map(fn (Role $role) => $role->value, Role::cases());
        $when = implode(' ', array_map(fn (int $i) => "when ? then {$i}", array_keys($names)));

        $query->orderByDesc('system')
            ->orderByRaw("case when system then case name {$when} end end", $names)
            ->orderByRaw('lower(label)')
            ->orderBy('id');
    }
}
