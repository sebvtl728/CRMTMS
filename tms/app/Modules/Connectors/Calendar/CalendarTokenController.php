<?php

namespace App\Modules\Connectors\Calendar;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
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
        $user = $this->user($request);
        $token = CalendarToken::activeFor($user);
        $crm = $user->can(Permissions::ACTIVITIES_VIEW);
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
            'crm' => $crm,
            'sources' => CalendarSources::serves($user),
            // Outlook carries the CRM actions and the items of the Outlook sources (a trainer's séances).
            'outlook' => $user->can('calendar.outlook') ? OutlookController::props($user) : null,
            'outlook_error' => $request->session()->get('outlook_error'),
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
