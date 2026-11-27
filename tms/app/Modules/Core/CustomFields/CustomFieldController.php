<?php

namespace App\Modules\Core\CustomFields;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration › Champs personnalisés (settings.manage): one tab per
 * entity of CustomFieldEntities (?entity=, the first one by default).
 */
class CustomFieldController extends Controller
{
    public function index(Request $request, CustomFieldDefinitions $definitions): Response
    {
        $entities = CustomFieldEntities::all();
        abort_if($entities === [], 404);
        $asked = $request->query('entity');
        $entity = is_string($asked) ? CustomFieldEntities::find($asked) : $entities[0];
        abort_if($entity === null, 404);

        $fields = $definitions->all($entity->key);
        $used = $definitions->usedAmong($fields);

        return Inertia::render('admin/custom-fields', [
            'status' => $request->session()->get('status'),
            'entity' => $entity->key,
            'entity_info' => ['records' => $entity->records, 'description' => $entity->description, 'required_label' => $entity->requiredLabel],
            'entities' => array_map(fn (CustomFieldEntity $e) => ['value' => $e->key, 'label' => $e->label], $entities),
            'fields' => $fields->map(fn (CustomFieldDefinition $f) => [
                'id' => $f->id,
                'label' => $f->label,
                'type' => $f->type->value,
                'type_label' => $f->type->label(),
                'options' => $f->options ?? [],
                'required' => $f->required_for_admission,
                'hidden' => $f->hidden_at !== null,
                'used' => isset($used[$f->id]),
            ])->values()->all(),
            'types' => $entity->typeOptions(),
        ]);
    }

    public function store(Request $request, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->create(self::entityKey($request), $request->except('entity'));

        return back()->with('status', 'Champ créé.');
    }

    public function update(Request $request, CustomFieldDefinition $customField, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->update($customField, $request->except('entity'));

        return back()->with('status', 'Champ enregistré.');
    }

    public function reorder(Request $request, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['uuid']])['ids'];
        $definitions->reorder(self::entityKey($request), array_values($ids));

        return back();
    }

    public function hide(CustomFieldDefinition $customField, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->hide($customField);

        return back()->with('status', 'Champ masqué.');
    }

    public function unhide(CustomFieldDefinition $customField, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->unhide($customField);

        return back()->with('status', 'Champ affiché.');
    }

    public function destroy(CustomFieldDefinition $customField, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->delete($customField);

        return back()->with('status', 'Champ supprimé.');
    }

    /** The entity of the request (a string), the first registered one when absent. */
    private static function entityKey(Request $request): string
    {
        $entity = $request->input('entity');

        return is_string($entity) && $entity !== '' ? $entity : (CustomFieldEntities::all()[0]->key ?? '');
    }
}
