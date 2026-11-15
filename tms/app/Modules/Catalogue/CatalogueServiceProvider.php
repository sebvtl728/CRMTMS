<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
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

        MorphAliases::register(['training' => Training::class]);

        $this->app->singleton(Trainings::class);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();

        RateLimiter::for('catalogue-export', fn (Request $request) => Limit::perMinute(10)->by('catalogue-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(['message' => 'Trop d\'exports en peu de temps. Réessayez dans une minute.'], 429, $headers)));

        Gate::policy(Training::class, TrainingPolicy::class);

        // A training of another organisme (RLS) or deleted is a 404.
        Route::bind('training', function (string $value): Training {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Training::class, [$value]);
            }

            return Training::query()->findOrFail($value);
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
    }
}
