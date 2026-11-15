<?php

namespace App\Modules\Core\Access;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Rôles et droits » (roles.manage): the organisme's roles (system and
 * custom, RoleCatalogue) and their permission matrix, one cell saved at a
 * time. {role} is the id of a role of the current organisme (404 otherwise).
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleMatrix $matrix, private readonly RoleCatalogue $catalogue) {}

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
            'roles' => RoleModel::query()->ordered()->get()->map(fn (RoleModel $role) => [
                'id' => $role->id,
                'value' => $role->name,
                'label' => $role->label(),
                'system' => $role->system,
                'requires_2fa' => $role->requires2fa(),
                'locked' => ! RoleMatrix::editable($role),
                'granted' => RoleMatrix::granted($role),
                'defaults' => RoleMatrix::defaults($role),
            ])->values()->all(),
            // Administration last, after the modules' themes.
            'groups' => [...array_values(array_diff_key($groups, [Permissions::CORE_GROUP => true])), ...(isset($groups[Permissions::CORE_GROUP]) ? [$groups[Permissions::CORE_GROUP]] : [])],
            'locked' => Permissions::LOCKED,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->catalogue->create((string) $request->label(), (bool) $request->requires2fa(), $request->copyFrom());

        return back()->with('status', "Rôle « {$role->label()} » créé.");
    }

    /**
     * Label and/or « double authentification obligatoire ».
     */
    public function modify(RoleRequest $request, string $role): RedirectResponse
    {
        $role = $this->find($role);
        $status = [];

        $label = $request->label();
        if ($label !== null && $label !== $role->label()) {
            $this->catalogue->rename($role, $label);
            $status[] = "Rôle renommé : {$role->label()}.";
        }
        $required = $request->requires2fa();
        if ($required !== null && $required !== $role->requires2fa()) {
            $this->catalogue->setRequires2fa($role, $required);
            $status[] = $required
                ? "Double authentification obligatoire pour le rôle {$role->label()}."
                : "Double authentification facultative pour le rôle {$role->label()}.";
        }

        return back()->with('status', $status === [] ? 'Aucune modification.' : implode(' ', $status));
    }

    public function destroy(string $role): RedirectResponse
    {
        $role = $this->find($role);
        $label = $role->label();
        $this->catalogue->delete($role);

        return back()->with('status', "Rôle « {$label} » supprimé.");
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $role = $this->find($role);
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
        $role = $this->find($role);
        $this->matrix->reset($role);

        return back()->with('status', $role->system
            ? "Droits par défaut rétablis pour le rôle {$role->label()}."
            : "Tous les droits du rôle {$role->label()} ont été retirés.");
    }

    /**
     * A role of the current organisme (BelongsToTenant + RLS), 404 otherwise.
     */
    private function find(string $id): RoleModel
    {
        return RoleModel::query()->whereKey($id)->where('guard_name', RoleSeeder::GUARD)->first() ?? abort(404);
    }
}
