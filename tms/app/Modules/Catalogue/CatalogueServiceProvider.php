<?php

namespace App\Modules\Catalogue;

use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Brochures\BrochurePolicy;
use App\Modules\Catalogue\Brochures\BrochureVersion;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Todo\TodoSources;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Module Catalogue: the organisme's trainings (lot 1d), read by the CRM
 * through Trainings only. Catalogue never depends on the CRM: modules that
 * use a training tell it through TrainingUsage.
 */
class CatalogueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        CorePermissions::registerModule('catalogue', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP);

        TrainingCustomFields::registerEntity();
        MorphAliases::register(['training' => Training::class, 'training_module' => TrainingModule::class, 'brochure' => Brochure::class, 'brochure_version' => BrochureVersion::class]);

        $this->app->singleton(Trainings::class);
        $this->app->singleton(Brochures::class);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();
        Quality\CatalogueMeasures::register();
        // « À traiter »: the RNCP certifications to watch (inactive, ending, unknown).
        TodoSources::register('catalogue.rncp', 'Catalogue', 'Certifications RNCP à surveiller (inactives, inconnues ou finissant dans 6 mois)', Permissions::MANAGE,
            fn (): int => TrainingListQuery::rncpAlerts(Training::query())->count(),
            fn (): string => '/catalogue?rncp=alerte');

        // Programme PDFs (Gotenberg): 30 a minute per user.
        RateLimiter::for('catalogue-programme', fn (Request $request) => Limit::perMinute(30)->by('catalogue-programme:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('catalogue-export', fn (Request $request) => Limit::perMinute(10)->by('catalogue-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(['message' => 'Trop d\'exports en peu de temps. Réessayez dans une minute.'], 429, $headers)));

        Gate::policy(Training::class, TrainingPolicy::class);
        Gate::policy(Brochure::class, BrochurePolicy::class);

        // A training of another organisme (RLS) or deleted is a 404.
        Route::bind('training', function (string $value): Training {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Training::class, [$value]);
            }

            return Training::query()->findOrFail($value);
        });

        // A brochure of another organisme (RLS) or in the trash is a 404, except for the download of a
        // version (the controller then requires that this version was sent).
        Route::bind('brochure', function (string $value, \Illuminate\Routing\Route $route): Brochure {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Brochure::class, [$value]);
            }
            $query = $route->getName() === 'catalogue.brochures.versions.download' ? Brochure::withTrashed() : Brochure::query();

            return $query->findOrFail($value);
        });
    }

    /**
     * Reference columns of the catalogue (an item in use cannot be deleted).
     * Public so tests can re-register after ReferenceUsage::flush().
     */
    public static function registerReferenceUsage(): void
    {
        ReferenceUsage::register(ReferenceList::Das, 'trainings', 'das_id');
        ReferenceUsage::register(ReferenceList::FundingMode, 'training_funding_modes', 'funding_mode_id');
        ReferenceUsage::register(ReferenceList::TrainingModality, 'training_modules', 'modality_id');
        ReferenceUsage::register(ReferenceList::Das, 'brochure_das', 'das_id');
    }
}
