<?php

namespace App\Modules\Crm\Timeline;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The « Historique » feed of a fiche, loaded by the page in pages of 30
 * (opaque cursor = position of the last entry shown, see TimelineCursor).
 */
class TimelineController extends Controller
{
    public const PAGE = 30;

    public function organisation(Request $request, Organisation $organisation): JsonResponse
    {
        return $this->feed($request, $organisation);
    }

    public function person(Request $request, Person $person): JsonResponse
    {
        return $this->feed($request, $person);
    }

    private function feed(Request $request, Model $subject): JsonResponse
    {
        Gate::authorize('view', $subject);

        // Opaque cursor; anything that does not decode is the first page.
        $cursor = $request->query('before');
        $before = is_string($cursor) ? TimelineCursor::decode($cursor) : null;

        $entries = Timeline::for($subject, $before, self::PAGE + 1);
        $more = $entries->count() > self::PAGE;
        $page = $entries->take(self::PAGE)->values();

        return response()->json([
            'entries' => $page->map->toArray()->all(),
            'next' => $more ? $page->last()?->cursor()->encode() : null,
        ]);
    }
}
