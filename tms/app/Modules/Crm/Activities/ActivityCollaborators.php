<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The other people an action or a meeting is assigned to (« Attribuer à »
 * with several people: the first is the responsible), besides its
 * responsible: active users of the organisme whose role lets them see the
 * actions (crm.activities.view, whatever its scope - never a role name; the
 * direction only reads them by default). They see and change the action
 * (CrmVisibility), in « À faire » and the planning too, never delete it
 * through that alone; the statistics count it for the responsible only.
 */
final class ActivityCollaborators
{
    public const MAX = 10;

    public const INVALID = 'Choisissez des collaborateurs qui ont accès aux actions (pas le responsable).';

    /**
     * Ids of the users who may be added.
     *
     * @return list<string>
     */
    public static function eligibleIds(): array
    {
        return DB::table('model_has_roles as mr')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.tenant_id', app(TenantContext::class)->id())
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('p.name', Permissions::ACTIVITIES_VIEW)
            ->whereNull('u.deactivated_at')
            ->distinct()->pluck('mr.model_id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * The choices of the « Collaborateurs » field, by name; the current
     * ones kept even when no longer eligible.
     *
     * @param  list<string>  $currentIds
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $currentIds = []): array
    {
        $ids = array_values(array_unique([...self::eligibleIds(), ...$currentIds]));

        return User::query()->whereKey($ids)->orderBy('name')->get(['id', 'name', 'deactivated_at'])
            ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->deactivated_at === null ? $u->name : $u->name.' (désactivé)'])->values()->all();
    }

    /** The action ids where $userId is a collaborator (a subquery). */
    public static function idsOf(string $userId): Builder
    {
        return DB::table('activity_collaborators')->select('activity_id')->where('user_id', $userId);
    }

    /**
     * Replaces the collaborators of $activity (validated ids).
     *
     * @param  list<string>  $userIds
     */
    public static function sync(Activity $activity, array $userIds): void
    {
        $before = $activity->collaborators()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
        $activity->collaborators()->sync(array_fill_keys($userIds, ['tenant_id' => $activity->tenant_id]));
        foreach (array_unique([...$before, ...$userIds]) as $userId) {
            TodoQuery::forget($userId);
        }
    }
}
