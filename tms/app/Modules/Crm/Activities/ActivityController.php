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
 * page), « Fait » with the next follow-up, « Modifier » and removal. Writes answer
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
            // « Attribuer à »: active users, the author by default.
            'responsibles' => FormOptions::responsible(),
            'responsible_user_id' => $user->id,
            'collaborators' => ActivityCollaborators::options(),
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
    private static function choices(?string $currentResultId = null, ?string $currentTypeId = null): array
    {
        return [
            // Active types, plus the current one of an edited action (« Modifier »).
            'types' => ReferenceItem::query()->where('list', ReferenceList::ActionType)
                ->where(fn ($q) => $q->where('is_active', true)->when($currentTypeId, fn ($q2, $id) => $q2->orWhere('id', $id)))
                ->orderBy('position')->orderBy('label')->get()
                ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->is_active ? $i->label : $i->label.' (inactif)', 'code' => $i->code])->values()->all(),
            'results' => FormOptions::reference(ReferenceList::ActionResult, $currentResultId),
        ];
    }

    public function store(ActivityRequest $request): RedirectResponse
    {
        [, $next] = $this->recorder->create($request->values(), $request->next(), $request->collaborators() ?? []);

        return back()->with('status', $next === null ? 'Action enregistrée.' : 'Action enregistrée, prochaine relance prévue.');
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        Gate::authorize('view', $activity);
        $user = $request->user();
        assert($user instanceof User);

        $canUpdate = $user->can('update', $activity);

        return response()->json([
            'activity' => ActivityRows::row($activity->load(ActivityRows::WITH)),
            ...self::choices($activity->result_id, $activity->type_id),
            // « Modifier »: active users (the current one kept, even deactivated).
            'responsibles' => $canUpdate ? FormOptions::responsible($activity->responsible_user_id) : [],
            'collaborators' => $canUpdate ? ActivityCollaborators::options($activity->collaborators()->pluck('users.id')->map(fn ($id) => (string) $id)->all()) : [],
            'can' => ['update' => $canUpdate, 'delete' => $user->can('delete', $activity)],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(ActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->recorder->update($activity, $request->values(), $request->collaborators());

        return back()->with('status', 'Action modifiée.');
    }

    /**
     * « Confier à… »: another responsible, told by e-mail (not oneself).
     */
    public function assign(AssignRequest $request, Activity $activity): JsonResponse|RedirectResponse
    {
        $this->recorder->assign($activity, $request->responsibleId(), $request->message());
        $name = User::query()->whereKey($activity->responsible_user_id)->value('name');
        $status = 'Action confiée à '.(is_string($name) ? $name : 'un collègue').'.';

        return $request->expectsJson()
            ? response()->json(['status' => $status])
            : back()->with('status', $status);
    }

    public function postpone(PostponeRequest $request, Activity $activity): RedirectResponse
    {
        DB::transaction(fn () => $activity->forceFill(['due_at' => $request->dueAt()])->save());

        return back()->with('status', 'Action reportée.');
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
