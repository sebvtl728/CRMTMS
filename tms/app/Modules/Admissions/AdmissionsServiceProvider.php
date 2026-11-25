<?php

namespace App\Modules\Admissions;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Alternance\AlternanceFacts;
use App\Modules\Core\Automation\DailyAutomations;
use App\Modules\Admissions\Booklet\BookletAccess;
use App\Modules\Admissions\Booklet\BookletAutomation;
use App\Modules\Admissions\Booklet\BookletEvaluation;
use App\Modules\Admissions\Booklet\BookletMail;
use App\Modules\Admissions\Booklet\BookletMonth;
use App\Modules\Admissions\Booklet\BookletVisit;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\Crm\CandidatureLinks;
use App\Modules\Admissions\Crm\CandidaturesBlock;
use App\Modules\Admissions\CustomFields\CustomFieldDefinition;
use App\Modules\Admissions\Gdpr\CandidatureAnonymisation;
use App\Modules\Admissions\Gdpr\PersonCandidaturesExport;
use App\Modules\Admissions\History\CandidatureHistory;
use App\Modules\Admissions\OnlineTests\OnlineAttempt;
use App\Modules\Admissions\OnlineTests\OnlineTest;
use App\Modules\Admissions\Positioning\PositioningSending;
use App\Modules\Admissions\Positioning\PositioningSendings;
use App\Modules\Admissions\Todo\AdmissionsTodo;
use App\Modules\Catalogue\Brochures;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Sessions\Public\DossierBlocks;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Module Admissions: candidatures of CRM people, their alternance contract
 * and custom fields. Reaches the other modules through their public services
 * only; nobody imports it: it declares itself in their registries.
 */
class AdmissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        foreach (Permissions::GROUPS as $group => $permissions) {
            CorePermissions::registerModule(
                'admissions.'.explode('.', $permissions[0])[1],
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
            Candidature::MORPH_ALIAS => Candidature::class,
            CandidatureFile::MORPH_ALIAS => CandidatureFile::class,
            AlternanceContract::MORPH_ALIAS => AlternanceContract::class,
            CustomFieldDefinition::MORPH_ALIAS => CustomFieldDefinition::class,
            PositioningSending::MORPH_ALIAS => PositioningSending::class,
            OnlineTest::MORPH_ALIAS => OnlineTest::class,
            OnlineAttempt::MORPH_ALIAS => OnlineAttempt::class,
            BookletMonth::MORPH_ALIAS => BookletMonth::class,
            BookletEvaluation::MORPH_ALIAS => BookletEvaluation::class,
            BookletVisit::MORPH_ALIAS => BookletVisit::class,
        ]);

        PositioningSendings::registerTemplate();
        BookletMail::registerTemplates();
    }

    public function boot(): void
    {
        AdmissionsTodo::register();
        self::registerReferenceUsage();
        self::registerTrainingUsage();
        self::registerCrm();
        Quality\AdmissionsMeasures::register();
        // The apprenticeship booklet: monthly link of the past month and reminders (Administration › Automatismes).
        DailyAutomations::register('booklet', fn (CarbonImmutable $today): int => app(BookletAutomation::class)->run($today));
        // The documents of a dossier know its alternance contract (convention d'apprentissage / de professionnalisation).
        Paperwork::registerAlternance(fn (string $dossierId): ?array => AlternanceFacts::forDossier($dossierId));

        // A test sent to a candidate stays downloadable from the history once trashed in the library.
        Brochures::registerSentCheck('admissions.positioning', fn (string $versionId): bool => DB::table('positioning_sending_versions')->where('brochure_version_id', $versionId)->exists());

        // The dossier of an apprenant shows its candidature.
        DossierBlocks::register('admissions.candidature', function (string $dossierId, User $viewer): ?array {
            if (! $viewer->can(Permissions::CANDIDATURES_VIEW)) {
                return null;
            }
            $rows = Candidature::query()->where('dossier_id', $dossierId)->get()
                ->filter(fn (Candidature $c) => AdmissionsVisibility::allows($viewer, Permissions::CANDIDATURES_VIEW, $c))
                ->map(fn (Candidature $c) => ['id' => $c->id, 'title' => $c->number, 'subtitle' => $c->status->label(), 'href' => "/admissions/candidatures/{$c->id}"])
                ->values()->all();

            return $rows === [] ? null : ['title' => 'Candidature', 'empty' => 'Aucune candidature.', 'rows' => $rows];
        }, 10);

        // 404 outside the view scope; the trash too for who may restore.
        Route::bind('candidature', function (string $value): Candidature {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Candidature::class, [$value]);
            }
            $user = request()->user();
            $query = Candidature::query();
            if (! $user instanceof User) {
                return $query->findOrFail($value);
            }
            if (Scopes::of($user, Permissions::CANDIDATURES_DELETE) !== null) {
                $query->withTrashed();
            }

            return AdmissionsVisibility::candidatures($query, $user)->findOrFail($value);
        });

        // The booklet follow-up: 404 out of the scope of admissions.booklet.manage.
        Route::bind('bookletCandidature', function (string $value): Candidature {
            $user = request()->user();
            if (! Str::isUuid($value) || ! $user instanceof User) {
                throw (new ModelNotFoundException)->setModel(Candidature::class, [$value]);
            }

            return BookletAccess::candidatures(Candidature::query(), $user)->findOrFail($value);
        });
    }

    /**
     * What the module adds to the CRM fiches: the block « Candidatures » of a
     * person (with « Ouvrir une candidature »), her history, and what the
     * fiches keep (import undo, merge, anonymisation, RGPD export and
     * retention).
     */
    public static function registerCrm(): void
    {
        FicheBlocks::register('person', 'admissions.candidatures', fn (string $id, User $viewer) => CandidaturesBlock::for($id, $viewer));
        CandidatureHistory::registerOnFiches();
        FicheLinks::registerLinked('admissions.candidatures', fn (string $type, string $id): bool => CandidatureLinks::linked($type, $id));
        FicheLinks::registerMerge('admissions.candidatures', fn (string $type, string $keepId, string $dropId) => CandidatureLinks::merge($type, $keepId, $dropId));
        FicheLinks::registerAnonymisation('admissions.candidatures', fn (array $personIds) => app(CandidatureAnonymisation::class)->forPeople($personIds));
        FicheLinks::registerRetentionHold('admissions.candidatures', fn (CarbonImmutable $threshold) => CandidatureLinks::retentionHold($threshold));
        FicheLinks::registerExport('admissions', fn (string $personId): array => PersonCandidaturesExport::for($personId), [Permissions::CANDIDATURES_VIEW, Permissions::SENSITIVE_VIEW]);
    }

    public static function registerReferenceUsage(): void
    {
        ReferenceUsage::register(ReferenceList::Das, 'candidatures', 'das_id');
        ReferenceUsage::register(ReferenceList::ContactOrigin, 'candidatures', 'origin_id');
        ReferenceUsage::register(ReferenceList::CandidatureRefusalReason, 'candidatures', 'decision_reason_id');
        ReferenceUsage::register(ReferenceList::Das, 'online_test_das', 'das_id');
    }

    public static function registerTrainingUsage(): void
    {
        TrainingUsage::register('candidatures', fn (string $trainingId): bool => Candidature::withTrashed()->where('training_id', $trainingId)->exists());
    }
}
