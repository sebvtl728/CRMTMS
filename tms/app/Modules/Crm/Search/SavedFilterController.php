<?php

namespace App\Modules\Crm\Search;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * CRUD of the saved filters. Visible: own filters and the shared ones of the
 * organisme (the route binding answers 404 for any other). Editable and
 * deletable by the author only.
 */
class SavedFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $resource = is_string($request->query('resource')) ? CrmResource::tryFrom($request->query('resource')) : null;

        $filters = SavedFilter::visibleTo($user)->with('author')
            ->when($resource, fn ($q, CrmResource $r) => $q->where('resource', $r->value))
            ->orderBy('name')->get();

        return response()->json(['data' => $filters->map(fn (SavedFilter $f) => $f->present($user))->all()]);
    }

    public function store(SavedFilterRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);

        if (SavedFilter::query()->where('user_id', $user->getKey())->count() >= SavedFilter::MAX_PER_USER) {
            throw ValidationException::withMessages(['name' => 'Vous avez atteint le nombre maximal de filtres enregistrés ('.SavedFilter::MAX_PER_USER.').']);
        }

        $filter = new SavedFilter([
            'resource' => $request->resource()->value,
            'name' => trim($request->validated('name')),
            'filters' => $request->cleanFilters(),
            'columns' => $request->validated('columns'),
            'is_shared' => (bool) $request->validated('is_shared', false),
        ]);
        $filter->save();

        return $request->wantsJson()
            ? response()->json(['data' => $filter->load('author')->present($user)], 201)
            : back()->with('status', 'Filtre enregistré.');
    }

    public function update(SavedFilterRequest $request, SavedFilter $savedFilter): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);
        $this->mustBeAuthor($user, $savedFilter);

        $data = $request->validated();
        $savedFilter->fill([
            'name' => isset($data['name']) ? trim($data['name']) : $savedFilter->name,
            'filters' => array_key_exists('filters', $data) ? $request->cleanFilters() : $savedFilter->filters,
            'columns' => array_key_exists('columns', $data) ? $data['columns'] : $savedFilter->columns,
            'is_shared' => array_key_exists('is_shared', $data) ? (bool) $data['is_shared'] : $savedFilter->is_shared,
        ])->save();

        return $request->wantsJson()
            ? response()->json(['data' => $savedFilter->load('author')->present($user)])
            : back()->with('status', 'Filtre mis à jour.');
    }

    public function destroy(Request $request, SavedFilter $savedFilter): Response|RedirectResponse
    {
        $this->mustBeAuthor($this->user($request), $savedFilter);
        $savedFilter->delete();

        return $request->wantsJson() ? response()->noContent() : back()->with('status', 'Filtre supprimé.');
    }

    private function mustBeAuthor(User $user, SavedFilter $filter): void
    {
        abort_unless($filter->user_id === $user->getKey(), 403, 'Seul l\'auteur d\'un filtre peut le modifier ou le supprimer.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
