<?php

namespace App\Modules\Crm\Visibility;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\RoleScopesChanged;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Core\Users\UserPageExtensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * « DAS suivis » of a user whose role uses the « Ses DAS » scope on one of
 * its rights: the public service of the CRM for the users page (plugged
 * through UserPageExtensions, Core never imports the CRM) and the clean-up
 * when a user's new role no longer uses that scope.
 */
final class FollowedDas
{
    /**
     * @return list<string> ids of the DAS followed by $user
     */
    public static function ids(User $user): array
    {
        return DB::table('user_das')->where('user_id', $user->getKey())->pluck('das_id')->all();
    }

    public static function registerOnUsersPage(): void
    {
        UserPageExtensions::props(fn (Request $request): array => [
            'dasOptions' => ReferenceItem::options(ReferenceList::Das)->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->values(),
        ]);
        UserPageExtensions::rows(fn (User $user): array => ['das_ids' => self::ids($user), 'follows_das' => self::applies($user)]);
    }

    /**
     * One of the user's roles uses « Ses DAS »: his followed DAS mean something.
     */
    public static function applies(User $user): bool
    {
        foreach ($user->roles()->get() as $role) {
            if ($role instanceof RoleModel && Scopes::roleUses($role, AccessScope::Das)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The followed DAS only mean something with « Ses DAS »: a new role that
     * no longer uses it empties them (audited), so nothing silently comes
     * back with a later role.
     */
    public static function onRoleChanged(PrimaryRoleChanged $event): void
    {
        if (! Scopes::roleUses($event->role, AccessScope::Das)) {
            self::clear($event->user);
        }
    }

    /**
     * Same rule when the matrix of a role changes: once the role no longer
     * uses « Ses DAS » on any right, its users (unless another of their roles
     * still uses it) lose their followed DAS.
     */
    public static function onRoleScopesChanged(RoleScopesChanged $event): void
    {
        if (Scopes::roleUses($event->role, AccessScope::Das)) {
            return;
        }

        // Only the holders that follow DAS have something to lose.
        $users = User::query()
            ->whereIn('id', DB::table('model_has_roles')
                ->where('role_id', $event->role->getKey())
                ->where('model_type', (new User)->getMorphClass())
                ->select('model_id'))
            ->whereIn('id', DB::table('user_das')->select('user_id'))
            ->get();

        foreach ($users as $user) {
            if (! self::applies($user)) {
                self::clear($user);
            }
        }
    }

    private static function clear(User $user): void
    {
        $before = self::ids($user);
        if ($before === []) {
            return;
        }

        sort($before);
        DB::transaction(function () use ($user, $before): void {
            DB::table('user_das')->where('user_id', $user->getKey())->delete();
            app(AuditRecorder::class)->record('users.das_changed', $user, ['das_ids' => $before], ['das_ids' => []]);
        });
    }
}
