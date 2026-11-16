<?php

namespace App\Modules\Sessions;

use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\InvitationAccepted;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Crm\Public\QuoteLinks;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierFromQuote;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierPolicy;
use App\Modules\Sessions\Dossiers\DossierRows;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentPolicy;
use App\Modules\Sessions\Enrolments\EnrolmentRows;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Gdpr\EnrolmentAnonymisation;
use App\Modules\Sessions\Gdpr\FicheReferences;
use App\Modules\Sessions\Gdpr\PersonSessionsExport;
use App\Modules\Sessions\Gdpr\RetentionHolds;
use App\Modules\Sessions\History\SessionsHistory;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\SessionPolicy;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\TrainerRows;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
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
                array_intersect_key(Permissions::extraScopes(), array_flip($permissions)),
            );
        }

        MorphAliases::register([
            TrainingSession::MORPH_ALIAS => TrainingSession::class,
            Dossier::MORPH_ALIAS => Dossier::class,
            DossierLine::MORPH_ALIAS => DossierLine::class,
            Enrolment::MORPH_ALIAS => Enrolment::class,
            Funding::MORPH_ALIAS => Funding::class,
            SessionModule::MORPH_ALIAS => SessionModule::class,
            Room::MORPH_ALIAS => Room::class,
            ClosurePeriod::MORPH_ALIAS => ClosurePeriod::class,
            Trainer::MORPH_ALIAS => Trainer::class,
            TrainerUnavailability::MORPH_ALIAS => TrainerUnavailability::class,
        ]);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();
        self::registerTrainingUsage();
        self::registerFicheBlocks();
        self::registerFicheLinks();
        SessionsHistory::registerOnFiches();
        // « Créer le dossier » / « Voir le dossier » on an accepted quote.
        QuoteLinks::register('sessions.dossier', fn (string $quoteId, User $viewer) => app(DossierFromQuote::class)->link($quoteId, $viewer));

        // An invited account is linked to the one trainer having its e-mail (else by hand).
        Event::listen(InvitationAccepted::class, fn (InvitationAccepted $event) => Trainers::linkInvited($event->user));
        // A user whose séances right becomes « Ses séances » (role Formateur) gets his trainer record.
        Event::listen(PrimaryRoleChanged::class, function (PrimaryRoleChanged $event): void {
            if (Scopes::of($event->user, Permissions::SEANCES_VIEW) === AccessScope::Trainer) {
                Trainers::ensureForUser($event->user);
            }
        });

        // Rooms, closure periods, trainers and their unavailabilities: malformed
        // or of another organisme (RLS) is a 404.
        self::bindUuid('room', Room::class);
        self::bindUuid('closurePeriod', ClosurePeriod::class);
        self::bindUuid('trainer', Trainer::class);
        self::bindUuid('unavailability', TrainerUnavailability::class);

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

        // A module of a session: malformed or of another organisme (RLS) is a
        // 404; the controller checks it belongs to the session of the route.
        Route::bind('sessionModule', function (string $value): SessionModule {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(SessionModule::class, [$value]);
            }

            return SessionModule::query()->findOrFail($value);
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
     * @param  class-string<Model>  $class
     */
    private static function bindUuid(string $key, string $class): void
    {
        Route::bind($key, function (string $value) use ($class): Model {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel($class, [$value]);
            }

            return $class::query()->findOrFail($value);
        });
    }

    /**
     * Blocks of the CRM fiches (Crm\Public\FicheBlocks): « Formations
     * suivies » of a person (sessions.sessions.view and its scope),
     * « Dossiers » of an entreprise or a particulier (sessions.dossiers.view
     * and its scope), « Formateur » of a trainer (sessions.trainers.manage).
     */
    public static function registerFicheBlocks(): void
    {
        FicheBlocks::register('person', 'sessions.trainer', fn (string $id, User $viewer) => TrainerRows::personBlock($id, $viewer));
        FicheBlocks::register('person', 'sessions.enrolments', fn (string $id, User $viewer) => EnrolmentRows::personBlock($id, $viewer));
        FicheBlocks::register('person', 'sessions.dossiers', fn (string $id, User $viewer) => DossierRows::personBlock($id, $viewer));
        FicheBlocks::register('organisation', 'sessions.dossiers', fn (string $id, User $viewer) => DossierRows::organisationBlock($id, $viewer));
    }

    /**
     * What the module keeps on the CRM fiches (Crm\Public\FicheLinks):
     * an import undo keeps a fiche a session, a dossier, an enrolment or a
     * funding names; a merge repoints them (refused for two people enrolled
     * on the same session); the anonymisation clears adaptation needs and
     * notes; the RGPD export lists her enrolments and dossiers (downloadable
     * with both view rights of the module on « Tout l'organisme »); the RGPD
     * retention never lists a person still enrolled, contractant, contact or
     * funder within the period (RetentionHolds).
     */
    public static function registerFicheLinks(): void
    {
        FicheLinks::registerLinked('sessions.references', fn (string $type, string $id): bool => FicheReferences::linked($type, $id));
        FicheLinks::registerMerge('sessions.references', fn (string $type, string $keepId, string $dropId) => FicheReferences::merge($type, $keepId, $dropId));
        FicheLinks::registerAnonymisation('sessions.enrolments', fn (array $personIds) => EnrolmentAnonymisation::scrub($personIds));
        FicheLinks::registerAnonymisation('sessions.trainers', fn (array $personIds) => Trainers::anonymise($personIds));
        FicheLinks::registerRetentionHold('sessions.enrolments', fn (CarbonImmutable $threshold) => RetentionHolds::enrolments($threshold));
        FicheLinks::registerRetentionHold('sessions.dossiers', fn (CarbonImmutable $threshold) => RetentionHolds::dossiers($threshold));
        FicheLinks::registerRetentionHold('sessions.fundings', fn (CarbonImmutable $threshold) => RetentionHolds::fundings($threshold));
        FicheLinks::registerExport('sessions', fn (string $personId): array => PersonSessionsExport::for($personId), [Permissions::DOSSIERS_VIEW, Permissions::SESSIONS_VIEW]);
    }

    /**
     * Reference columns of the module (an item in use cannot be deleted).
     * Public so tests can re-register after ReferenceUsage::flush().
     */
    public static function registerReferenceUsage(): void
    {
        ReferenceUsage::register(ReferenceList::Das, 'training_sessions', 'das_id');
        ReferenceUsage::register(ReferenceList::CancelReason, 'training_sessions', 'cancel_reason_id');
        ReferenceUsage::register(ReferenceList::TrainingModality, 'training_sessions', 'modality_id');
        ReferenceUsage::register(ReferenceList::TrainingModality, 'session_modules', 'modality_id');
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
