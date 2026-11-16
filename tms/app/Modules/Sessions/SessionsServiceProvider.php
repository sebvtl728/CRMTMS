<?php

namespace App\Modules\Sessions;

use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierPolicy;
use App\Modules\Sessions\Dossiers\DossierRows;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentPolicy;
use App\Modules\Sessions\Enrolments\EnrolmentRows;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Sessions\SessionPolicy;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Module Sessions (lot 2a): dossiers, sessions de formation, inscriptions.
 * It reaches the CRM through App\Modules\Crm\Public only and the catalogue
 * through Trainings; neither depends on it (registries and tags of theirs).
 */
class SessionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        foreach (Permissions::GROUPS as $group => $permissions) {
            CorePermissions::registerModule(
                'sessions.'.explode('.', $permissions[0])[1],
                $permissions,
                fn (Role $role): array => array_values(array_intersect(Permissions::defaultsFor($role), $permissions)),
                Permissions::labels(),
                $group,
                array_values(array_intersect(Permissions::scoped(), $permissions)),
                fn (Role $role): array => array_intersect_key(Permissions::defaultScopesFor($role), array_flip($permissions)),
            );
        }

        MorphAliases::register([
            TrainingSession::MORPH_ALIAS => TrainingSession::class,
            Dossier::MORPH_ALIAS => Dossier::class,
            DossierLine::MORPH_ALIAS => DossierLine::class,
            Enrolment::MORPH_ALIAS => Enrolment::class,
            Funding::MORPH_ALIAS => Funding::class,
        ]);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();
        self::registerTrainingUsage();
        self::registerFicheBlocks();

        Gate::policy(TrainingSession::class, SessionPolicy::class);
        Gate::policy(Dossier::class, DossierPolicy::class);
        Gate::policy(Enrolment::class, EnrolmentPolicy::class);

        // A session outside the view scope, of another organisme (RLS) or
        // malformed is a 404; a trashed one only with sessions.sessions.delete.
        Route::bind('trainingSession', function (string $value): TrainingSession {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(TrainingSession::class, [$value]);
            }
            $user = request()->user();
            $query = TrainingSession::query();
            if (! $user instanceof User) {
                return $query->findOrFail($value);
            }
            if (Scopes::of($user, Permissions::SESSIONS_DELETE) !== null) {
                $query->withTrashed();
            }

            return SessionsVisibility::sessions($query, $user)->findOrFail($value);
        });

        // Same rules for a dossier with sessions.dossiers.view / delete.
        Route::bind('dossier', function (string $value): Dossier {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Dossier::class, [$value]);
            }
            $user = request()->user();
            $query = Dossier::query();
            if (! $user instanceof User) {
                return $query->findOrFail($value);
            }
            if (Scopes::of($user, Permissions::DOSSIERS_DELETE) !== null) {
                $query->withTrashed();
            }

            return SessionsVisibility::dossiers($query, $user)->findOrFail($value);
        });

        // An enrolment: its session within the view scope (trashed ones with
        // sessions.sessions.delete), otherwise 404.
        Route::bind('enrolment', function (string $value): Enrolment {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Enrolment::class, [$value]);
            }
            $user = request()->user();
            if (! $user instanceof User) {
                return Enrolment::query()->findOrFail($value);
            }
            $sessions = TrainingSession::query();
            if (Scopes::of($user, Permissions::SESSIONS_DELETE) !== null) {
                $sessions->withTrashed();
            }

            return Enrolment::query()
                ->whereIn('training_session_id', SessionsVisibility::sessions($sessions, $user)->select('training_sessions.id'))
                ->findOrFail($value);
        });
    }

    /**
     * Blocks of the CRM fiches (Crm\Public\FicheBlocks): « Formations
     * suivies » of a person (sessions.sessions.view and its scope),
     * « Dossiers » of an entreprise (sessions.dossiers.view and its scope).
     */
    public static function registerFicheBlocks(): void
    {
        FicheBlocks::register('person', 'sessions.enrolments', fn (string $id, User $viewer) => EnrolmentRows::personBlock($id, $viewer));
        FicheBlocks::register('organisation', 'sessions.dossiers', fn (string $id, User $viewer) => DossierRows::organisationBlock($id, $viewer));
    }

    /**
     * Reference columns of the module (an item in use cannot be deleted).
     * Public so tests can re-register after ReferenceUsage::flush().
     */
    public static function registerReferenceUsage(): void
    {
        ReferenceUsage::register(ReferenceList::Das, 'training_sessions', 'das_id');
        ReferenceUsage::register(ReferenceList::CancelReason, 'training_sessions', 'cancel_reason_id');
        ReferenceUsage::register(ReferenceList::Das, 'dossiers', 'das_id');
        ReferenceUsage::register(ReferenceList::Das, 'dossier_lines', 'das_id');
        ReferenceUsage::register(ReferenceList::CancelReason, 'dossiers', 'cancelled_reason_id');
        ReferenceUsage::register(ReferenceList::AbandonReason, 'enrolments', 'abandon_reason_id');
        ReferenceUsage::register(ReferenceList::BpfCategory, 'enrolments', 'bpf_category_id');
        ReferenceUsage::register(ReferenceList::FundingType, 'fundings', 'funding_type_id');
    }

    /**
     * A training used by a session or a line of a dossier (trashed ones
     * included: they can come back) is never deleted from the catalogue.
     */
    public static function registerTrainingUsage(): void
    {
        TrainingUsage::register('sessions', fn (string $trainingId): bool => TrainingSession::withTrashed()->where('training_id', $trainingId)->exists()
            || DossierLine::query()->where('training_id', $trainingId)->exists());
    }
}
