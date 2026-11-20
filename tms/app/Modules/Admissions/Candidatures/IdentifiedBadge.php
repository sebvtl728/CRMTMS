<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Cache;

/**
 * Red counter of « Candidats » in the menu: the candidatures still at the
 * « Identifié » step in the DAS of a user whose candidatures right is on
 * « Ses DAS » (a DAS responsible, read from the right's scope, never the
 * role); 0 for anyone else. A candidature leaves the count when it leaves
 * the step. Cached 60 s under the tenant.
 */
final class IdentifiedBadge
{
    public const TTL = 60;

    public static function count(User $user): int
    {
        if (! $user->can(Permissions::CANDIDATURES_VIEW) || Scopes::of($user, Permissions::CANDIDATURES_VIEW) !== AccessScope::Das) {
            return 0;
        }
        $key = 'tenant:'.app(TenantContext::class)->id().':admissions:identified:'.$user->id;

        return Cache::remember($key, self::TTL, fn (): int => AdmissionsVisibility::candidatures(Candidature::query(), $user)
            ->where('candidatures.status', CandidatureStatus::Identified->value)->count());
    }
}
