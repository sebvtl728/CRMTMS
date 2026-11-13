<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Access\Role;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rule: an organisme always keeps at least one active administrator. Every
 * path that can remove one (role change, deactivation, any future suspension
 * of accounts) goes through ensureStays(), inside its own transaction.
 *
 * Paths that do not remove an administrator need no guard: tms:user:reset-2fa
 * only clears the second factor (the account stays active, with its role) and
 * a tenant suspension (tms:tenant:suspend) touches neither accounts nor roles.
 */
class LastAdminGuard
{
    public const MESSAGE = 'Il doit rester au moins un administrateur actif.';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Locks the active administrators (stable order) and refuses the change
     * when it would remove the last one; reloads the target under the lock.
     * Must run in a transaction.
     *
     * @param  bool  $leavesAdmins  false when the change keeps the target an active administrator
     *
     * @throws ValidationException
     */
    public function ensureStays(User $target, bool $leavesAdmins = true): void
    {
        // Role changes write model_has_roles, never the users row, so row locks alone
        // do not serialise them and, in READ COMMITTED, a waiting transaction would keep
        // a stale view of who is still an admin. A per-organisme advisory lock, taken
        // before counting, makes every path that can remove an admin run one at a time;
        // the count below is a fresh statement, so it sees what the previous one committed.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['last-admin:'.$this->tenants->id()]);

        $admins = User::query()
            ->whereNull('deactivated_at')
            ->whereNotNull('password')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::AdminOrganisme->value))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($leavesAdmins && $admins->count() === 1 && $admins->contains('id', $target->getKey())) {
            throw ValidationException::withMessages(['user' => self::MESSAGE]);
        }

        // The target may have changed since the route resolved it.
        $target->refresh();
    }
}
