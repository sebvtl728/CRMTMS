<?php

namespace App\Modules\Crm\Visibility;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * « DAS suivis » of a Responsable DAS (users.manage), audited.
 */
class UserDasController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'das_ids' => ['present', 'array'],
            'das_ids.*' => ['distinct', Rule::exists('reference_items', 'id')
                ->where('tenant_id', $this->tenants->id())->where('list', ReferenceList::Das->value)->whereNull('deleted_at')],
        ], [
            'das_ids.*.exists' => 'Ce DAS n\'existe pas ou n\'est plus disponible.',
        ]);

        if ($user->role() !== Role::ResponsableDas) {
            throw ValidationException::withMessages(['das_ids' => 'Seul un utilisateur ayant le rôle Responsable DAS suit des DAS.']);
        }

        $after = array_values($data['das_ids']);
        $before = CrmVisibility::followedDas($user);

        DB::transaction(function () use ($user, $after): void {
            DB::table('user_das')->where('user_id', $user->getKey())->delete();
            DB::table('user_das')->insert(array_map(fn (string $id) => [
                'tenant_id' => $this->tenants->id(), 'user_id' => $user->getKey(), 'das_id' => $id,
            ], $after));
        });

        sort($before);
        sort($after);
        if ($before !== $after) {
            $this->audit->record('users.das_changed', $user, ['das_ids' => $before], ['das_ids' => $after]);
        }

        return back()->with('status', 'DAS suivis enregistrés pour '.$user->name.'.');
    }
}
