<?php

namespace App\Modules\Admissions\CustomFields;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Administration › Champs personnalisés (settings.manage). */
class CustomFieldController extends Controller
{
    public function index(CustomFieldDefinitions $definitions): Response
    {
        $fields = $definitions->all();
        $used = $definitions->usedAmong($fields);

        return Inertia::render('admin/custom-fields', [
            'status' => request()->session()->get('status'),
            'fields' => $fields->map(fn (CustomFieldDefinition $f) => [
                'id' => $f->id,
                'label' => $f->label,
                'type' => $f->type->value,
                'type_label' => $f->type->label(),
                'options' => $f->options ?? [],
                'required_for_admission' => $f->required_for_admission,
                'hidden' => $f->hidden_at !== null,
                'used' => isset($used[$f->id]),
            ])->values()->all(),
            'types' => CustomFieldType::options(),
        ]);
    }

    public function store(Request $request, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->create($request->all());

        return back()->with('status', 'Champ créé.');
    }

    public function update(Request $request, CustomFieldDefinition $customField, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $definitions->update($customField, $request->all());

        return back()->with('status', 'Champ enregistré.');
    }

    public function reorder(Request $request, CustomFieldDefinitions $definitions): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['uuid']])['ids'];
        $definitions->reorder(array_values($ids));

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
}
