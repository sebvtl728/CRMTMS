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
        $url = $request->session()->get('calendar_url');

        // The link is a page prop, kept in the browser history state: that
        // state is encrypted, and its key is rotated by the next page
        // rendered (flag set after this response is built), so « back »
        // can never show the link again.
        Inertia::encryptHistory();

        $response = Inertia::render('settings/calendar', [
            'calendar' => $token === null ? null : [
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ],
            'url' => $url,
            'status' => $request->session()->get('status'),
        ]);
        if ($url !== null) {
            Inertia::clearHistory();
        }

        return $response;
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
