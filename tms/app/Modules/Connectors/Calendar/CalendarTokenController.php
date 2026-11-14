<?php

namespace App\Modules\Connectors\Calendar;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Mon agenda » in the user settings: the private link of his iCal feed,
 * shown once right after it is generated (flashed for the next page only).
 */
class CalendarTokenController extends Controller
{
    public function edit(Request $request): Response
    {
        $token = CalendarToken::activeFor($this->user($request));

        return Inertia::render('settings/calendar', [
            'calendar' => $token === null ? null : [
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ],
            'url' => $request->session()->get('calendar_url'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $replaced = CalendarToken::activeFor($user) !== null;
        $plain = CalendarToken::issue($user);

        return to_route('calendar.settings')
            ->with('calendar_url', route('calendar.feed', ['token' => $plain]))
            ->with('status', $replaced ? 'Nouveau lien créé : l\'ancien ne fonctionne plus.' : 'Lien d\'abonnement créé.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        CalendarToken::revoke($this->user($request));

        return to_route('calendar.settings')->with('status', 'Lien révoqué : votre agenda ne reçoit plus vos rendez-vous.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
