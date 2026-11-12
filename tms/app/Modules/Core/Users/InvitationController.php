<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User administration of the current organisme (permission users.manage).
 */
class InvitationController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/users/index', [
            'status' => $request->session()->get('status'),
            'users' => User::query()->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->isActive(),
                'activated' => $user->password !== null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]),
            'invitations' => Invitation::query()->pending()->orderBy('email')->get()->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role->label(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->isExpired(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/invite', [
            'roles' => Role::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'email' => [
                'required', 'string', 'email', 'max:255',
                // An activated account already uses this address in this organisme.
                Rule::unique('users', 'email')
                    ->where('tenant_id', $this->tenants->id())
                    ->whereNotNull('password'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'email.unique' => 'Un compte existe déjà avec cette adresse e-mail.',
        ]);

        /** @var User $inviter */
        $inviter = $request->user();
        $invitation = Invitation::issue($validated['email'], $validated['name'], Role::from($validated['role']), $inviter);

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification(
            url: route('activation.show', $invitation->plainToken),
            name: $invitation->name,
            organisation: $this->tenants->current()->name ?? (string) config('app.name'),
        ));

        $this->audit->record('users.invitation_sent', $invitation, [], ['email' => $invitation->email, 'role' => $invitation->role->value]);

        return to_route('admin.users.index')->with('status', 'Invitation envoyée à '.$invitation->email.'.');
    }
}
