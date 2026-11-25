<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;

/**
 * Who receives a new candidature: the DAS responsibles of its DAS, i.e. the
 * active users whose candidatures right is on « Ses DAS » (read from the
 * right's scope, never the role, as IdentifiedBadge) and who follow it.
 */
final class DasRecipients
{
    /**
     * @return array<string, list<string>> names keyed by DAS id, sorted
     */
    public static function names(): array
    {
        $candidates = Scopes::usersWith(Permissions::CANDIDATURES_VIEW, AccessScope::Das);
        if ($candidates === []) {
            return [];
        }
        $users = User::query()->whereKey($candidates)->whereNull('deactivated_at')->orderBy('name')->get()
            ->filter(fn (User $u) => Scopes::of($u, Permissions::CANDIDATURES_VIEW) === AccessScope::Das)
            ->keyBy('id');

        $names = [];
        foreach (UserDas::followers() as $dasId => $userIds) {
            $list = $users->only($userIds)->map(fn (User $u) => $u->name)->values()->all();
            if ($list !== []) {
                $names[$dasId] = $list;
            }
        }

        return $names;
    }
}
