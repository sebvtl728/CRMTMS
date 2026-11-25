<?php

namespace App\Modules\Sessions;

use App\Modules\Catalogue\TrainingBlocks;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Connectors\Calendar\CalendarSources;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleScopesChanged;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\Automation\DailyAutomations;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\InvitationAccepted;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Crm\Public\QuoteLinks;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\AttendanceSheetScan;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\CatchUpLink;
use App\Modules\Sessions\Attendance\Signing\CatchUpMailTemplates;
use App\Modules\Sessions\Attendance\Signing\SignedSheet;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Automation\DayBeforeMailTemplates;
use App\Modules\Sessions\Automation\DayBeforeReminders;
use App\Modules\Sessions\Dashboard\Retention;
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
use App\Modules\Sessions\Notes\SessionNote;
use App\Modules\Sessions\Notes\SessionTask;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\PlacementPeriod;
use App\Modules\Sessions\Planning\PlacementPeriodHost;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceCalendar;
use App\Modules\Sessions\Planning\SeanceOutlook;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Resources\Rooms;
use App\Modules\Sessions\Sessions\SessionPolicy;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Summary\TrainingSessionsBlock;
use App\Modules\Sessions\Todo\SessionsTodo;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\TrainerDas;
use App\Modules\Sessions\Trainers\TrainerRows;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerUnavailability;
use App\Modules\Sessions\Trainers\TrainerVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
            TrainerDas::MORPH_ALIAS => TrainerDas::class,
            Seance::MORPH_ALIAS => Seance::class,
            EnrolmentAdjustment::MORPH_ALIAS => EnrolmentAdjustment::class,
            PlacementPeriod::MORPH_ALIAS => PlacementPeriod::class,
            PlacementPeriodHost::MORPH_ALIAS => PlacementPeriodHost::class,
            Attendance::MORPH_ALIAS => Attendance::class,
            MonthlyHour::MORPH_ALIAS => MonthlyHour::class,
            AttendanceClosure::MORPH_ALIAS => AttendanceClosure::class,
            AttendanceSheetScan::MORPH_ALIAS => AttendanceSheetScan::class,
            SignSlot::MORPH_ALIAS => SignSlot::class,
            AttendanceSignature::MORPH_ALIAS => AttendanceSignature::class,
            CatchUpLink::MORPH_ALIAS => CatchUpLink::class,
            SignedSheet::MORPH_ALIAS => SignedSheet::class,
            SessionTask::MORPH_ALIAS => SessionTask::class,
            SessionNote::MORPH_ALIAS => SessionNote::class,
        ]);
    }

    public function boot(): void
    {
        // Lot 1e: the retention card of the CRM dashboards.
        Retention::register();
        // E-mails of the remote signing catch-up (2026-11-21).
        CatchUpMailTemplates::register();
        DayBeforeMailTemplates::register();
        SessionsTodo::register();
        DailyAutomations::register('day_before', fn (CarbonImmutable $today): int => app(DayBeforeReminders::class)->run($today));
        self::registerReferenceUsage();
        self::registerTrainingUsage();
        self::registerFicheBlocks();
        Quality\SessionsMeasures::register();
        // « Sessions » block of the training page.
        TrainingBlocks::register('sessions', fn (string $trainingId, User $viewer) => TrainingSessionsBlock::for($trainingId, $viewer));
        self::registerFicheLinks();
        self::registerPlanningUsage();
        self::registerAttendance();
        SessionsHistory::registerOnFiches();
        // The iCal feed (Connectors): séances of the linked trainer and of the sessions one is responsible for.
        CalendarSources::register('sessions.seances', SeanceCalendar::eventsFor(...), SeanceCalendar::serves(...));
        // …and the séances of a trainer in his Outlook calendar (Connectors pushes them).
        SeanceOutlook::register();
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
        // …and so does every user of a role whose rights or scopes change that way (after commit).
        Event::listen(RoleScopesChanged::class, fn (RoleScopesChanged $event) => Trainers::ensureForTrainerScoped(
            DB::table('model_has_roles')->where('role_id', $event->role->getKey())->where('model_type', (new User)->getMorphClass())
                ->pluck('model_id')->map(fn ($id) => (string) $id)->all(),
        ));

        // Rooms, closure periods, trainers and their unavailabilities: malformed
        // or of another organisme (RLS) is a 404.
        self::bindUuid('room', Room::class);
        self::bindUuid('closurePeriod', ClosurePeriod::class);
        // A trainer outside the sessions.trainers.manage scope is a 404.
        Route::bind('trainer', function (string $value): Trainer {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Trainer::class, [$value]);
            }
            $user = request()->user();

            // Without the right the route's `can` middleware answers 403.
            $scoped = $user instanceof User && Scopes::of($user, Permissions::TRAINERS_MANAGE) !== null;

            return ($scoped ? TrainerVisibility::scoped(Trainer::query(), $user) : Trainer::query())->findOrFail($value);
        });
        self::bindUuid('unavailability', TrainerUnavailability::class);
        self::bindUuid('placementPeriod', PlacementPeriod::class);

        // A séance: within the user's sessions.seances.view scope, else 404
        // (malformed, of another organisme, in the trash).
        Route::bind('seance', function (string $value): Seance {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Seance::class, [$value]);
            }
            $user = request()->user();

            return ($user instanceof User ? SessionsVisibility::seances(Seance::query(), $user) : Seance::query())->findOrFail($value);
        });

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

        // A task or a note of a session: malformed or of another organisme
        // (RLS) is a 404; the controller checks it belongs to the session of the route.
        foreach (['sessionTask' => SessionTask::class, 'sessionNote' => SessionNote::class] as $name => $model) {
            Route::bind($name, function (string $value) use ($model): Model {
                if (! Str::isUuid($value)) {
                    throw (new ModelNotFoundException)->setModel($model, [$value]);
                }

                return $model::query()->findOrFail($value);
            });
        }

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
     * A room or a trainer named by a séance (out of the trash) is never
     * deleted: deactivated instead.
     */
    public static function registerPlanningUsage(): void
    {
        Rooms::registerUsage('sessions.seances', fn (string $roomId): bool => Seance::query()->where('room_id', $roomId)->exists());
        Trainers::registerUsage('sessions.seances', fn (string $trainerId): bool => Seance::query()->where('trainer_id', $trainerId)->exists());
    }

    /**
     * The attendance and the séances (spec § 4 bis 5, 6): a séance with
     * attendance is never deleted; a closed month refuses its séances
     * (created, moved, cancelled, deleted; 409); a séance moved or
     * cancelled reviews its attendance in the same transaction.
     */
    public static function registerAttendance(): void
    {
        Seances::registerAttendance('sessions.attendance', fn (string $seanceId): bool => Attendance::query()->where('seance_id', $seanceId)->exists());
        Seances::guardMonthsWith(fn (TrainingSession $session, string $date) => Closures::assertOpen($session->id, $date));
        Seances::onScheduleChange('sessions.attendance', fn (Seance $seance, string $kind) => Attendances::review($seance, $kind));
        Seances::onScheduleChange('sessions.individual_planning', fn (Seance $seance) => IndividualPlanning::refreshForSeance($seance));
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
        ReferenceUsage::register(ReferenceList::Das, 'trainer_das', 'das_id');
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
