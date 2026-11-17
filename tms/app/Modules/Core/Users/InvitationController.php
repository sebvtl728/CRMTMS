<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Calendar\GraphRetryLater;
use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Access\RoleCatalogue;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'roleOptions' => RoleCatalogue::options(),
            // Modules add their own props/columns (« DAS suivis » of the CRM).
            ...UserPageExtensions::pageProps($request),
            'users' => User::query()->with('roles')->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'role' => $user->role()?->name,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->isActive(),
                'is_self' => $user->is($request->user()),
                'two_factor' => $user->hasTwoFactorEnabled(),
                'activated' => $user->password !== null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                ...UserPageExtensions::rowData($user),
            ]),
            'invitations' => Invitation::query()->pending()->orderBy('email')->get()->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->roleLabel(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->isExpired(),
            ]),
        ]);
    }

    /**
     * The form, optionally prefilled from the query (email, name, role):
     * « Inviter » of a module's page (a trainer).
     */
    public function create(Request $request): Response
    {
        $prefill = fn (string $key, int $max): string => is_string($value = $request->query($key)) ? mb_substr(trim($value), 0, $max) : '';

        return Inertia::render('admin/users/invite', [
            'roles' => RoleCatalogue::options(),
            'defaults' => ['email' => $prefill('email', 254), 'name' => $prefill('name', 255), 'role' => $prefill('role', 100)],
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
            'role' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || RoleModel::named($value) === null) {
                    $fail('Ce rôle n\'existe pas.');
                }
            }],
        ], [
            'email.unique' => 'Un compte existe déjà avec cette adresse e-mail.',
        ]);

        /** @var User $inviter */
        $inviter = $request->user();
        $role = RoleModel::named($validated['role']) ?? throw ValidationException::withMessages(['role' => 'Ce rôle n\'existe pas.']);
        $invitation = Invitation::issue($validated['email'], $validated['name'], $role, $inviter);

        return $this->deliver($invitation, 'sent');
    }

    /**
     * New link for a pending invitation: the previous token stops working at
     * once (issue() replaces the pending invitation) and the 24 h restart.
     */
    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        /** @var User $inviter */
        $inviter = $request->user();
        $role = $invitation->roleModel()
            ?? throw ValidationException::withMessages(['invitation' => 'Le rôle de cette invitation n\'existe plus : révoquez-la puis invitez de nouveau.']);
        $fresh = DB::transaction(fn () => Invitation::issue($invitation->email, $invitation->name, $role, $inviter));

        return $this->deliver($fresh, 'resent');
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        DB::transaction(function () use ($invitation) {
            $this->audit->record('users.invitation_revoked', $invitation, [], ['email' => $invitation->email, 'role' => $invitation->role, 'role_label' => $invitation->roleLabel()]);
            $invitation->delete();
        });

        return to_route('admin.users.index')->with('status', 'Invitation à '.$invitation->email.' révoquée : son lien ne fonctionne plus.');
    }

    /**
     * Sends the activation link; when nothing can be delivered, keeps the
     * invitation and shows the link to the administrator (once).
     *
     * @param  'sent'|'resent'  $kind
     */
    private function deliver(Invitation $invitation, string $kind): RedirectResponse
    {
        $url = route('activation.show', $invitation->plainToken);
        $company = Company::query()->where('is_default', true)->first();
        $audited = ['email' => $invitation->email, 'role' => $invitation->role, 'role_label' => $invitation->roleLabel()];
        $verb = $kind === 'resent' ? 'renvoyée' : 'envoyée';

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
            $reason = match (true) {
                $e instanceof SmtpHostRefused, $e instanceof PermanentDeliveryFailure => $e->getMessage(),
                $e instanceof GraphRetryLater => 'Microsoft 365 est injoignable pour le moment. Réessayez dans quelques minutes.',
                $e instanceof TransportExceptionInterface => 'Le serveur SMTP a refusé la connexion ou l\'envoi.',
                default => 'Erreur inattendue lors de l\'envoi.',
            };
            Log::warning('Invitation e-mail not sent.', ['exception' => $e::class]);
            $this->audit->record('users.invitation_send_failed', $invitation, [], [...$audited, 'reason' => $reason]);

            return $this->withLink($url, 'L\'e-mail d\'invitation à '.$invitation->email.' n\'a pas pu être envoyé ('.rtrim($reason, '.').'). L\'invitation est conservée : transmettez-lui ce lien d\'activation :');
        }

        $this->audit->record($kind === 'resent' ? 'users.invitation_resent' : 'users.invitation_sent', $invitation, [], $audited);

        return to_route('admin.users.index')->with('status', 'Invitation '.$verb.' à '.$invitation->email.'.');
    }

    /**
     * Back to the list with the activation link, shown once to the administrator.
     */
    private function withLink(string $url, string $warning): RedirectResponse
    {
        return to_route('admin.users.index')->with('warning', $warning)->with('activation_link', $url);
    }
}
