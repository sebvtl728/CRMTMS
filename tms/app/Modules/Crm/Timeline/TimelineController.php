<?php

namespace App\Modules\Crm\Timeline;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The « Historique » feed of a fiche, loaded by the page in pages of 30
 * (cursor = instant of the oldest entry shown).
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

        $before = null;
        $cursor = $request->query('before');
        if (is_string($cursor) && $cursor !== '') {
            try {
                $before = CarbonImmutable::parse($cursor);
            } catch (\Throwable) {
                abort(422, 'Curseur invalide.');
            }
        }

        $entries = Timeline::for($subject, $before, self::PAGE + 1);
        $more = $entries->count() > self::PAGE;
        $page = $entries->take(self::PAGE)->values();

        return response()->json([
            'entries' => $page->map->toArray()->all(),
            'next' => $more ? $page->last()?->at->toIso8601ZuluString('microsecond') : null,
        ]);
    }
}
