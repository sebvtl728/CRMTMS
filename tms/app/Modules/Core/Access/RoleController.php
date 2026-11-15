<?php

namespace App\Modules\Core\Access;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Rôles et droits » (roles.manage): the permission matrix of the
 * organisme's roles, one cell saved at a time.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleMatrix $matrix) {}

    public function index(Request $request): Response
    {
        $groups = [];
        foreach (Permissions::all() as $permission) {
            $label = Permissions::label($permission) ?? ['label' => $permission, 'description' => '', 'group' => 'Autres'];
            $groups[$label['group']] ??= ['label' => $label['group'], 'permissions' => []];
            $groups[$label['group']]['permissions'][] = [
                'name' => $permission,
                'label' => $label['label'],
                'description' => $label['description'],
                'warning' => Permissions::WARNINGS[$permission] ?? null,
            ];
        }

        return Inertia::render('admin/roles/index', [
            'roles' => array_map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'locked' => ! RoleMatrix::editable($role),
                'granted' => RoleMatrix::granted($role),
                'defaults' => Permissions::defaultsFor($role),
            ], Role::cases()),
            // Administration last, after the modules' themes.
            'groups' => [...array_values(array_diff_key($groups, [Permissions::CORE_GROUP => true])), ...(isset($groups[Permissions::CORE_GROUP]) ? [$groups[Permissions::CORE_GROUP]] : [])],
            'locked' => Permissions::LOCKED,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $role = Role::tryFrom($role) ?? abort(404);
        $data = $request->validate([
            'permission' => ['required', 'string', Rule::in(Permissions::all())],
            'granted' => ['required', 'boolean'],
        ], [
            'permission.required' => 'Choisissez un droit.',
            'permission.in' => "Ce droit n'existe pas.",
            'granted.required' => 'Indiquez si le droit est accordé.',
            'granted.boolean' => 'Indiquez si le droit est accordé.',
        ]);

        $granted = filter_var($data['granted'], FILTER_VALIDATE_BOOLEAN);
        $this->matrix->set($role, $data['permission'], $granted);
        $label = Permissions::label($data['permission'])['label'] ?? $data['permission'];

        return back()->with('status', $granted
            ? "Droit « {$label} » accordé au rôle {$role->label()}."
            : "Droit « {$label} » retiré au rôle {$role->label()}.");
    }

    public function reset(string $role): RedirectResponse
    {
        $role = Role::tryFrom($role) ?? abort(404);
        $this->matrix->reset($role);

        return back()->with('status', "Droits par défaut rétablis pour le rôle {$role->label()}.");
    }
}
