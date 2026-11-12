<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

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
            'warning' => $request->session()->get('warning'),
            'activationLink' => $request->session()->get('activation_link'),
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
                'required', 'string', 'email', 'max:254',
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

        $url = route('activation.show', $invitation->plainToken);
        $company = Company::query()->where('is_default', true)->first();
        $audited = ['email' => $invitation->email, 'role' => $invitation->role->value];

        if (TenantMailer::deliversNowhere($company)) {
            // MAIL_MAILER=log/array: sending would only write the link to the logs.
            $this->audit->record('users.invitation_send_failed', $invitation, [], [...$audited, 'reason' => 'Messagerie non configurée.']);

            return $this->withLink($url, 'Invitation créée pour '.$invitation->email.', mais aucun e-mail n\'a été envoyé : la messagerie n\'est pas configurée. Transmettez-lui ce lien d\'activation :');
        }

        try {
            TenantMailer::for($company)->to($invitation->email)->send(new InvitationMail(
                url: $url,
                name: $invitation->name,
                organisation: $this->tenants->current()->name ?? (string) config('app.name'),
            ));
        } catch (Throwable $e) {
            // The server's raw text can echo credentials: only our own summary is kept.
            $reason = $e instanceof SmtpHostRefused ? $e->getMessage()
                : ($e instanceof TransportExceptionInterface ? 'Le serveur SMTP a refusé la connexion ou l\'envoi.' : 'Erreur inattendue lors de l\'envoi.');
            Log::warning('Invitation e-mail not sent.', ['exception' => $e::class]);
            $this->audit->record('users.invitation_send_failed', $invitation, [], [...$audited, 'reason' => $reason]);

            return $this->withLink($url, 'L\'e-mail d\'invitation à '.$invitation->email.' n\'a pas pu être envoyé ('.rtrim($reason, '.').'). L\'invitation est conservée : transmettez-lui ce lien d\'activation :');
        }

        $this->audit->record('users.invitation_sent', $invitation, [], $audited);

        return to_route('admin.users.index')->with('status', 'Invitation envoyée à '.$invitation->email.'.');
    }

    /**
     * Back to the list with the activation link, shown once to the administrator.
     */
    private function withLink(string $url, string $warning): RedirectResponse
    {
        return to_route('admin.users.index')->with('warning', $warning)->with('activation_link', $url);
    }
}
