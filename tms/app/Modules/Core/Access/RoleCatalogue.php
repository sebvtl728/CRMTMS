<?php

namespace App\Modules\Core\Access;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\Invitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * The roles of the current organisme: the six system roles (never deleted,
 * the administrator's never changed) and the custom roles created by the
 * administrator. Every change is journaled (labels and flags only: no
 * personal data) and drops the organisme's cached permissions.
 *
 * Labels: 2 to 80 characters, unique in the organisme ignoring case and
 * accents (and the system labels are reserved), checked while the
 * organisme's roles are locked, so two concurrent requests cannot both pass.
 */
final class RoleCatalogue
{
    public const DUPLICATE = 'Un rôle porte déjà ce libellé.';

    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    /**
     * @return list<array{value: string, label: string}> the select options (value = role name)
     */
    public static function options(): array
    {
        return RoleModel::query()->ordered()->get()
            ->map(fn (RoleModel $role) => ['value' => $role->name, 'label' => $role->label()])
            ->values()->all();
    }

    /**
     * A new custom role, empty or with the rights and scopes of $copyFrom
     * (never the LOCKED rights).
     */
    public function create(string $label, bool $requires2fa, ?RoleModel $copyFrom = null): RoleModel
    {
        $label = $this->checkedLength($label);

        $role = DB::transaction(function () use ($label, $requires2fa, $copyFrom): RoleModel {
            $this->ensureUnique($label);

            /** @var RoleModel $role */
            $role = RoleModel::create([
                'name' => RoleModel::CUSTOM_PREFIX.Str::uuid7(),
                'label' => $label,
                'guard_name' => RoleSeeder::GUARD,
                'requires_2fa' => $requires2fa,
                'system' => false,
            ]);

            if ($copyFrom !== null) {
                $permissions = array_values(array_diff($copyFrom->permissions()->pluck('name')->all(), Permissions::LOCKED));
                $role->givePermissionTo($permissions);
                $scopes = DB::table(Scopes::TABLE)->where('role_id', $copyFrom->getKey())->whereIn('permission', $permissions)->get(['permission', 'scope']);
                if ($scopes->isNotEmpty()) {
                    DB::table(Scopes::TABLE)->insert($scopes->map(fn (object $row) => [
                        'tenant_id' => $this->tenants->id(),
                        'role_id' => $role->getKey(),
                        'permission' => $row->permission,
                        'scope' => $row->scope,
                        'updated_at' => now(),
                    ])->all());
                }
            }

            $this->audit->record('roles.created', $role, [], array_filter([
                'label' => $label,
                'requires_2fa' => $requires2fa,
                'copied_from' => $copyFrom?->name,
            ], fn ($value) => $value !== null));

            return $role;
        });

        $this->forget();

        return $role;
    }

    public function rename(RoleModel $role, string $label): void
    {
        if ($role->isAdmin()) {
            throw new UserFacingHttpException(403, 'Le rôle Administrateur de l\'organisme ne se modifie pas.');
        }
        $label = $this->checkedLength($label);

        DB::transaction(function () use ($role, $label): void {
            $this->ensureUnique($label, $role);
            $before = $role->label();
            if ($before === $label) {
                return;
            }
            // A system role given back its own label keeps following the enum.
            $role->forceFill(['label' => $role->systemRole()?->label() === $label ? null : $label])->save();
            $this->audit->record('roles.renamed', $role, ['label' => $before], ['label' => $label]);
        });

        $this->forget();
    }

    /**
     * Takes effect at the user's next request (EnsureTwoFactorEnrolled reads
     * the roles at each request).
     */
    public function setRequires2fa(RoleModel $role, bool $required): void
    {
        if (in_array($role->systemRole(), [Role::AdminOrganisme, Role::Direction], true)) {
            throw new UserFacingHttpException(403, 'La double authentification est toujours obligatoire pour ce rôle.');
        }

        DB::transaction(function () use ($role, $required): void {
            $locked = RoleModel::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->requires_2fa === $required) {
                return;
            }
            $locked->forceFill(['requires_2fa' => $required])->save();
            $role->setRawAttributes($locked->getAttributes(), true);
            $this->audit->record('roles.2fa_changed', $role, ['requires_2fa' => ! $required], ['requires_2fa' => $required]);
        });

        $this->forget();
    }

    /**
     * Refused for a system role (403) and while a user (active or not) holds
     * the role or a non-expired invitation targets it (422).
     */
    public function delete(RoleModel $role): void
    {
        if ($role->system) {
            throw new UserFacingHttpException(403, 'Un rôle système ne se supprime pas.');
        }

        DB::transaction(function () use ($role): void {
            // Assignments and invitations lock the role row shared (lockedForAssignment).
            $locked = RoleModel::query()->whereKey($role->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $holders = DB::table('model_has_roles')->where('role_id', $locked->getKey())->count()
                + Invitation::query()->pending()->where('role', $locked->name)->where('expires_at', '>', now())->count();
            if ($holders > 0) {
                throw ValidationException::withMessages(['role' => "Ce rôle est attribué à {$holders} utilisateur(s) ou invitation(s) : changez-leur de rôle avant de le supprimer."]);
            }

            $this->audit->record('roles.deleted', $locked, ['label' => $locked->label(), 'requires_2fa' => $locked->requires_2fa], []);
            // Rights and scopes go with it (foreign keys on cascade).
            $locked->delete();
        });

        $this->forget();
    }

    /**
     * The role, locked shared until the end of the transaction, so a
     * concurrent delete() waits and then counts the new holder. Null when
     * the role no longer exists. Must run in a transaction.
     */
    public static function lockedForAssignment(string $name): ?RoleModel
    {
        return RoleModel::query()->where('name', $name)->where('guard_name', RoleSeeder::GUARD)->sharedLock()->first();
    }

    private function checkedLength(string $label): string
    {
        $label = Str::squish($label);
        $length = mb_strlen($label);
        if ($length < 2 || $length > 80) {
            throw ValidationException::withMessages(['label' => 'Le libellé doit contenir de 2 à 80 caractères.']);
        }

        return $label;
    }

    /**
     * Locks the organisme's roles and refuses a label already used (by
     * another role, or reserved by a system role).
     */
    private function ensureUnique(string $label, ?RoleModel $except = null): void
    {
        $wanted = RoleModel::normaliseLabel($label);
        $roles = RoleModel::query()->where('guard_name', RoleSeeder::GUARD)->orderBy('id')->lockForUpdate()->get();

        $taken = [];
        foreach ($roles as $role) {
            if ($except !== null && $role->is($except)) {
                continue;
            }
            $taken[] = RoleModel::normaliseLabel($role->label());
        }
        foreach (Role::cases() as $system) {
            if ($except?->systemRole() !== $system) {
                $taken[] = RoleModel::normaliseLabel($system->label());
            }
        }

        if (in_array($wanted, $taken, true)) {
            throw ValidationException::withMessages(['label' => self::DUPLICATE]);
        }
    }

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Scopes::forget();
    }
}
