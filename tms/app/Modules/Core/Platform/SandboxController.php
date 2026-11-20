<?php

namespace App\Modules\Core\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration › Bac à sable (settings.manage): 404 outside a sandbox
 * (a server never has one), a reset confirmed by typing RÉINITIALISER.
 */
class SandboxController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless(Sandbox::enabled(), 404);

        return Inertia::render('admin/sandbox', ['status' => $request->session()->get('status')]);
    }

    public function reset(Request $request, Sandbox $sandbox): RedirectResponse
    {
        abort_unless(Sandbox::enabled(), 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in([Sandbox::WORK, Sandbox::ALL])],
            'confirmation' => ['required', 'string', Rule::in([Sandbox::CONFIRMATION])],
        ], [
            'mode.*' => 'Choisissez ce qu\'il faut effacer.',
            'confirmation.*' => 'Tapez RÉINITIALISER pour confirmer.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $sandbox->reset($data['mode'], $user);

        return to_route('admin.sandbox.show')->with('status', $data['mode'] === Sandbox::ALL
            ? 'Bac à sable remis à zéro : organisme de démonstration neuf, votre compte seul.'
            : 'Données de travail effacées : comptes et réglages gardés, société de démonstration.');
    }
}
