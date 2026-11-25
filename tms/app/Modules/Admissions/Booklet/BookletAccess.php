<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who follows the apprenticeship booklet of a candidature: the right
 * admissions.booklet.manage on its scope — « Ses séances » (the dossier has
 * a live enrolment in a session where the user's trainer has a séance),
 * « Ses fiches » (responsible or creator of the candidature), « Ses DAS »,
 * « Tout ». Only candidatures with an alternance contract, out of the
 * trash and not anonymised. A trainer reaches the booklet, never the
 * candidature itself.
 */
final class BookletAccess
{
    /**
     * @param  Builder<Candidature>  $query
     * @return Builder<Candidature>
     */
    public static function candidatures(Builder $query, User $user): Builder
    {
        $model = $query->getModel();
        $query->whereIn($model->qualifyColumn('id'), AlternanceContract::query()->select('candidature_id'))
            ->whereNull($model->qualifyColumn('anonymised_at'));

        return match (Scopes::of($user, Permissions::BOOKLET_MANAGE)) {
            null => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => $query->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
                ->orWhere($model->qualifyColumn('created_by'), $user->getKey())),
            AccessScope::Das => $query->whereIn($model->qualifyColumn('das_id'), UserDas::ids($user)),
            AccessScope::Trainer => $query->whereIn($model->qualifyColumn('dossier_id'), Admission::trainerDossierIds($user)),
        };
    }

    public static function allows(User $user, Candidature $candidature): bool
    {
        return self::candidatures(Candidature::query()->whereKey($candidature->getKey()), $user)->exists();
    }
}
