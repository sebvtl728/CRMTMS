<?php

namespace App\Modules\Crm\Visibility;

use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Core\Users\UserPageExtensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * « DAS suivis » of a Responsable DAS: the public service of the CRM for the
 * users page (plugged through UserPageExtensions, Core never imports the CRM)
 * and the clean-up when a user leaves the Responsable DAS role.
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
        UserPageExtensions::rows(fn (User $user): array => ['das_ids' => self::ids($user)]);
    }

    /**
     * The followed DAS only mean something for a Responsable DAS: leaving the
     * role empties them (audited), so nothing silently comes back with it.
     */
    public static function onRoleChanged(PrimaryRoleChanged $event): void
    {
        if ($event->previous !== Role::ResponsableDas || $event->role === Role::ResponsableDas) {
            return;
        }

        $before = self::ids($event->user);
        if ($before === []) {
            return;
        }

        DB::table('user_das')->where('user_id', $event->user->getKey())->delete();
        sort($before);
        app(AuditRecorder::class)->record('users.das_changed', $event->user, ['das_ids' => $before], ['das_ids' => []]);
    }
}
