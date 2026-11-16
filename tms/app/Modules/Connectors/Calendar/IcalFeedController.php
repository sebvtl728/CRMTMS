<?php

namespace App\Modules\Connectors\Calendar;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * GET /calendar/{token}.ics, outside any session: the request host gives
 * the organisme (ResolveTenant), the token its user. Unknown, malformed,
 * revoked or other-organisme token, deactivated user or user with nothing
 * to publish (neither the CRM actions nor a calendar source, IcalFeed::allowed):
 * the same 404, nothing tells them apart.
 */
class IcalFeedController extends Controller
{
    public function __invoke(string $token): Response
    {
        $calendar = CalendarToken::findActive($token);
        $user = $calendar?->user;

        if ($calendar === null || $user === null || ! $user->isActive() || ! IcalFeed::allowed($user)) {
            abort(404);
        }

        $calendar->touchUse();

        return response(IcalFeed::for($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
