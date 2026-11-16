<?php

namespace App\Modules\Sessions\Trainers;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Sessions\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches which trainer: the scope of sessions.trainers.manage.
 *
 *  - not granted: nothing;
 *  - « Tout l'organisme » (all, or no row): every trainer;
 *  - « Ses DAS » (das): trainers attached to a DAS he follows, and those
 *    without any DAS (visible to every manager);
 *  - « Ses fiches » (own): trainers he created or linked to his account.
 */
final class TrainerVisibility
{
    /**
     * @param  Builder<Trainer>  $query
     * @return Builder<Trainer>
     */
    public static function scoped(Builder $query, User $user): Builder
    {
        return match (Scopes::of($user, Permissions::TRAINERS_MANAGE)) {
            null, AccessScope::Trainer => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => $query->where(fn (Builder $q) => $q
                ->where('trainers.created_by', $user->getKey())
                ->orWhere('trainers.user_id', $user->getKey())),
            AccessScope::Das => $query->where(fn (Builder $q) => $q
                ->whereNotExists(fn ($d) => $d->selectRaw('1')->from('trainer_das')->whereColumn('trainer_das.trainer_id', 'trainers.id'))
                ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('trainer_das')->whereColumn('trainer_das.trainer_id', 'trainers.id')
                    ->whereIn('trainer_das.das_id', UserDas::ids($user)))),
        };
    }

    public static function allows(User $user, Trainer $trainer): bool
    {
        return self::scoped(Trainer::query()->whereKey($trainer->getKey()), $user)->exists();
    }
}
