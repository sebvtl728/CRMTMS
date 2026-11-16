<?php

namespace App\Modules\Sessions;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches which session and which dossier: the scope
 * (Scopes) of the requested permission, as CrmVisibility does for the CRM.
 *
 *  - right not granted: nothing;
 *  - « Tout l'organisme » (all): everything in the organisme;
 *  - « Ses DAS » (das): das_id among the DAS he follows (Crm\Public\UserDas);
 *  - « Ses fiches » (own): he is responsible for it or created it;
 *  - « Ses séances » (trainer, sessions.seances.view and
 *    sessions.attendance.manage only): the séances whose trainer is linked
 *    to the user (Trainers::forUser), the sessions holding one of them, never
 *    a dossier.
 *
 * The scope of the right asked is used, never the name of a role: the view
 * scope filters lists and route bindings (404), update and delete their own.
 */
final class SessionsVisibility
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  on training_sessions
     * @return Builder<TModel>
     */
    public static function sessions(Builder $query, User $user, string $permission = Permissions::SESSIONS_VIEW): Builder
    {
        return self::scoped($query, $user, Scopes::of($user, $permission));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  on dossiers
     * @return Builder<TModel>
     */
    public static function dossiers(Builder $query, User $user, string $permission = Permissions::DOSSIERS_VIEW): Builder
    {
        return self::scoped($query, $user, Scopes::of($user, $permission));
    }

    /**
     * Séances reached by $permission (sessions.seances.* or
     * sessions.attendance.*): « Ses séances », those whose trainer_id is the
     * trainer linked to the user; otherwise those of the sessions the same
     * scope reaches. (Query on the seances table, created by task 3.)
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  on seances
     * @return Builder<TModel>
     */
    public static function seances(Builder $query, User $user, string $permission = Permissions::SEANCES_VIEW): Builder
    {
        $scope = Scopes::of($user, $permission);
        $model = $query->getModel();

        return match ($scope) {
            null => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Trainer => ($trainer = Trainers::forUser($user)) === null
                ? $query->whereRaw('false')
                : $query->where($model->qualifyColumn('trainer_id'), $trainer->id),
            AccessScope::Own, AccessScope::Das => $query->whereIn(
                $model->qualifyColumn('training_session_id'),
                self::scoped(TrainingSession::withTrashed(), $user, $scope)->select('training_sessions.id'),
            ),
        };
    }

    /**
     * « Ses séances » on sessions: the sessions holding a séance of the
     * trainer linked to the user. No séance exists before task 3 (table
     * seances): nothing yet. Task 3 completes this hook with
     * whereIn(id, seances where trainer_id = the linked trainer).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  on training_sessions
     * @return Builder<TModel>
     */
    public static function sessionsOfTrainer(Builder $query, User $user): Builder
    {
        return $query->whereRaw('false');
    }

    /**
     * $permission reaches $record, a session or a dossier (trashed or not).
     */
    public static function allows(User $user, string $permission, TrainingSession|Dossier $record): bool
    {
        $scope = Scopes::of($user, $permission);

        return match ($scope) {
            null => false,
            AccessScope::All => true,
            AccessScope::Own => in_array($user->getKey(), [$record->responsible_user_id, $record->created_by], true),
            AccessScope::Das => in_array($record->das_id, UserDas::ids($user), true),
            AccessScope::Trainer => $record instanceof TrainingSession
                && self::sessionsOfTrainer(TrainingSession::withTrashed()->whereKey($record->getKey()), $user)->exists(),
        };
    }

    /**
     * $permission lets the user create (or move) a record into DAS $dasId:
     * created by him it is his (Own); Das: one of the DAS he follows.
     */
    public static function allowsDas(User $user, string $permission, string $dasId): bool
    {
        return match (Scopes::of($user, $permission)) {
            // A trainer never creates a session nor a dossier.
            null, AccessScope::Trainer => false,
            AccessScope::All, AccessScope::Own => true,
            AccessScope::Das => in_array($dasId, UserDas::ids($user), true),
        };
    }

    /**
     * Responsible for it or created it (Own); das_id followed (Das);
     * « Ses séances » (Trainer): a session holding one of his séances, never
     * a dossier.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scoped(Builder $query, User $user, ?AccessScope $scope): Builder
    {
        $model = $query->getModel();

        return match ($scope) {
            null => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => $query->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
                ->orWhere($model->qualifyColumn('created_by'), $user->getKey())),
            AccessScope::Das => $query->whereIn($model->qualifyColumn('das_id'), UserDas::ids($user)),
            AccessScope::Trainer => $model instanceof TrainingSession ? self::sessionsOfTrainer($query, $user) : $query->whereRaw('false'),
        };
    }
}
