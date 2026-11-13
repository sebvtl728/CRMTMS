<?php

namespace App\Modules\Crm\Activities;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Support\FormOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Actions: quick entry (« + Action », from anywhere, prefilled from the
 * page), « Fait » with the next follow-up, edit and removal. Writes answer
 * with a redirect back (Inertia); the dialog reads its options and an
 * action as JSON.
 */
class ActivityController extends Controller
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * Options of the quick entry, and the fiches of the current page the
     * user may use (null otherwise: the dialog then offers a search).
     */
    public function options(Request $request): JsonResponse
    {
        Gate::authorize('create', Activity::class);
        $user = $request->user();
        assert($user instanceof User);

        $organisation = ActivityFiches::organisation(FilterInput::id($request->query('organisation')), $user);
        $person = ActivityFiches::person(FilterInput::id($request->query('person')), $user);
        $opportunity = ActivityFiches::opportunity(FilterInput::id($request->query('opportunity')), $user);
        if ($opportunity !== null && $organisation === null && $person === null) {
            $organisation = $opportunity->organisation_id !== null ? $opportunity->organisation : null;
            $person = $opportunity->person_id !== null ? $opportunity->person : null;
        }

        return response()->json([
            ...self::choices(),
            'context' => [
                'organisation' => $organisation === null ? null : ['id' => $organisation->id, 'name' => $organisation->name],
                'person' => $person === null ? null : ['id' => $person->id, 'name' => $person->fullName()],
                'opportunity' => $opportunity === null ? null : ['id' => $opportunity->id, 'name' => $opportunity->title],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Types (with their code: the dialog's shortcut buttons) and results.
     *
     * @return array{types: list<array{value: string, label: string, code: string}>, results: list<array{value: string, label: string}>}
     */
    private static function choices(?string $currentResultId = null): array
    {
        return [
            'types' => ReferenceItem::options(ReferenceList::ActionType)
                ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label, 'code' => $i->code])->values()->all(),
            'results' => FormOptions::reference(ReferenceList::ActionResult, $currentResultId),
        ];
    }

    public function store(ActivityRequest $request): RedirectResponse
    {
        [, $next] = $this->recorder->create($request->values(), $request->next());

        return back()->with('status', $next === null ? 'Action enregistrée.' : 'Action enregistrée, prochaine relance prévue.');
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        Gate::authorize('view', $activity);
        $user = $request->user();
        assert($user instanceof User);

        return response()->json([
            'activity' => ActivityRows::row($activity->load(ActivityRows::WITH)),
            ...self::choices($activity->result_id),
            'can' => ['update' => $user->can('update', $activity), 'delete' => $user->can('delete', $activity)],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(ActivityRequest $request, Activity $activity): RedirectResponse
    {
        DB::transaction(fn () => $activity->fill($request->values())->save());

        return back()->with('status', 'Action modifiée.');
    }

    public function complete(CompleteRequest $request, Activity $activity): RedirectResponse
    {
        $next = $this->recorder->complete($activity, $request->values(), $request->next());

        return back()->with('status', $next === null ? 'Action faite.' : 'Action faite, prochaine relance prévue.');
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        DB::transaction(fn () => $activity->delete());

        return back()->with('status', 'Action supprimée.');
    }
}
