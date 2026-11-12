<?php

namespace App\Modules\Core\Users;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account activation through an invitation link (/activation/{token}).
 * Unknown, already used or other-tenant token: 404. Expired token: 410.
 */
class ActivationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->pendingInvitation($token);

        return Inertia::render('auth/activate', [
            'token' => $token,
            'email' => $invitation->email,
            'name' => $invitation->name,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $this->pendingInvitation($token);

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        DB::transaction(function () use ($token, $validated) {
            // Re-read under lock: two concurrent submissions cannot both use it.
            $invitation = Invitation::query()->pending()
                ->where('token_hash', Invitation::hashToken($token))
                ->lockForUpdate()->first() ?? abort(404);

            $user = User::query()->where('email', $invitation->email)->lockForUpdate()->first();

            if ($user !== null && $user->password !== null) {
                throw ValidationException::withMessages([
                    'password' => 'Ce compte est déjà activé. Connectez-vous ou utilisez « Mot de passe oublié ».',
                ]);
            }

            $user ??= new User(['email' => $invitation->email, 'name' => $invitation->name]);
            $user->password = $validated['password'];
            // The link reached this mailbox: the address is verified.
            $user->email_verified_at ??= now();
            $user->save();

            $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

            InvitationAccepted::dispatch($invitation, $user);
        });

        return to_route('login')->with('status', 'Votre compte est activé. Vous pouvez vous connecter.');
    }

    private function pendingInvitation(string $token): Invitation
    {
        $invitation = Invitation::findPendingByToken($token);

        abort_if($invitation === null, 404, 'Ce lien d\'activation n\'est pas valide ou a déjà été utilisé.');
        abort_if($invitation->isExpired(), 410, 'Ce lien d\'activation a expiré. Demandez une nouvelle invitation à votre administrateur.');

        return $invitation;
    }
}
