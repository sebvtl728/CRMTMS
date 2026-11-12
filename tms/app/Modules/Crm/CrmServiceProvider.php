<?php

namespace App\Modules\Crm;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationPolicy;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        CorePermissions::registerModule('crm', Permissions::all(), Permissions::defaultsFor(...));
    }

    public function boot(): void
    {
        self::registerReferenceUsage();

        Gate::policy(Organisation::class, OrganisationPolicy::class);

        // A fiche the user may not see is a 404, whatever the action. Trashed
        // fiches resolve too (show, restore): the controller decides.
        Route::bind('organisation', function (string $value): Organisation {
            // A malformed id is a plain 404 (PostgreSQL would raise on the uuid cast).
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Organisation::class, [$value]);
            }

            $query = Organisation::withTrashed();
            $user = request()->user();

            if ($user instanceof User) {
                $query = CrmVisibility::organisations($query, $user);
            }

            return $query->findOrFail($value);
        });
    }

    /**
     * Reference columns used by the CRM tables (an item in use cannot be
     * deleted). Public so tests can re-register after ReferenceUsage::flush().
     */
    public static function registerReferenceUsage(): void
    {
        ReferenceUsage::register(ReferenceList::LegalFormOrg, 'organisations', 'legal_form_id');
        ReferenceUsage::register(ReferenceList::Sector, 'organisations', 'sector_id');
        ReferenceUsage::register(ReferenceList::EstablishmentType, 'organisations', 'establishment_type_id');
        ReferenceUsage::register(ReferenceList::CompanySize, 'organisations', 'company_size_id');
        ReferenceUsage::register(ReferenceList::ContactOrigin, 'organisations', 'origin_id');
        ReferenceUsage::register(ReferenceList::Das, 'organisation_das', 'das_id');
        ReferenceUsage::register(ReferenceList::Das, 'user_das', 'das_id');
    }
}
