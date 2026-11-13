<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of the accounts of the current organisme (permission users.manage):
 * role change, deactivation, reactivation.
 *
 * Rule: an organisme always keeps at least one active administrator
 * (LastAdminGuard, taken under an advisory lock, so two concurrent changes
 * cannot both pass and leave none).
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly LastAdminGuard $lastAdmin) {}

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        $role = Role::from($validated['role']);

        $changed = DB::transaction(function () use ($user, $role): bool {
            $this->lastAdmin->ensureStays($user, leavesAdmins: $role !== Role::AdminOrganisme);

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
            $this->lastAdmin->ensureStays($user, leavesAdmins: true);

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

    private function back(string $status): RedirectResponse
    {
        return to_route('admin.users.index')->with('status', $status);
    }
}
