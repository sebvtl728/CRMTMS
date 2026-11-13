<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of the accounts of the current organisme (permission users.manage):
 * role change, deactivation, reactivation.
 *
 * Rule: an organisme always keeps at least one active administrator. It is
 * checked under a row lock on the active administrators, so two concurrent
 * changes cannot both pass and leave none.
 */
class UserController extends Controller
{
    private const LAST_ADMIN = 'Il doit rester au moins un administrateur actif.';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        $role = Role::from($validated['role']);

        $changed = DB::transaction(function () use ($user, $role): bool {
            $this->guardLastAdmin($user, leavesAdmins: $role !== Role::AdminOrganisme);

            if ($user->role() === $role) {
                return false;
            }

            $user->assignPrimaryRole($role);

            return true;
        });

        if ($changed) {
            // A privilege change ends the other sessions of the user; the acting
            // administrator keeps the current one when changing their own role.
            $user->revokeOtherSessions($request->user()?->is($user) ? $request->session()->getId() : null);
        }

        return $this->back($changed ? 'Rôle de '.$user->name.' modifié : '.$role->label().'.' : 'Ce rôle est déjà celui de '.$user->name.'.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->is($user)) {
            throw ValidationException::withMessages(['user' => 'Vous ne pouvez pas désactiver votre propre compte.']);
        }

        DB::transaction(function () use ($user): void {
            $this->guardLastAdmin($user, leavesAdmins: true);

            if ($user->isActive()) {
                $user->deactivate();
                $this->audit->record('users.deactivated', $user, [], ['email' => $user->email]);
            }
        });

        return $this->back('Compte de '.$user->name.' désactivé : ses sessions sont terminées.');
    }

    public function reactivate(User $user): RedirectResponse
    {
        DB::transaction(function () use ($user): void {
            if (! $user->isActive()) {
                $user->reactivate();
                $this->audit->record('users.reactivated', $user, [], ['email' => $user->email]);
            }
        });

        return $this->back('Compte de '.$user->name.' réactivé. Il doit se reconnecter.');
    }

    /**
     * Locks the active administrators (stable order) and refuses the change
     * when it would remove the last one; reloads the target under the lock.
     * Must run in a transaction.
     *
     * @throws ValidationException
     */
    private function guardLastAdmin(User $target, bool $leavesAdmins): void
    {
        // Role changes write model_has_roles, never the users row, so row locks alone
        // do not serialise them and, in READ COMMITTED, a waiting transaction would keep
        // a stale view of who is still an admin. A per-organisme advisory lock, taken
        // before counting, makes every path that can remove an admin run one at a time;
        // the count below is a fresh statement, so it sees what the previous one committed.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['last-admin:'.app(TenantContext::class)->id()]);

        $admins = User::query()
            ->whereNull('deactivated_at')
            ->whereNotNull('password')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::AdminOrganisme->value))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($leavesAdmins && $admins->count() === 1 && $admins->contains('id', $target->getKey())) {
            throw ValidationException::withMessages(['user' => self::LAST_ADMIN]);
        }

        // The target may have changed since the route resolved it.
        $target->refresh();
    }

    private function back(string $status): RedirectResponse
    {
        return to_route('admin.users.index')->with('status', $status);
    }
}
