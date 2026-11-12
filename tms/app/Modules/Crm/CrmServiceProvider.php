<?php

namespace App\Modules\Crm;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationPolicy;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PersonPolicy;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\AttachmentsProvider;
use App\Modules\Crm\Timeline\AuditProvider;
use App\Modules\Crm\Timeline\Note;
use App\Modules\Crm\Timeline\NotesProvider;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
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

        MorphAliases::register([
            'organisation' => Organisation::class,
            'person' => Person::class,
            'affiliation' => Affiliation::class,
            'note' => Note::class,
            'attachment' => Attachment::class,
        ]);

        // Sources of the « Historique »; later increments tag their own.
        $this->app->tag([NotesProvider::class, AttachmentsProvider::class, AuditProvider::class], Timeline::TAG);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();

        Gate::policy(Organisation::class, OrganisationPolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);

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

        Route::bind('person', function (string $value): Person {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Person::class, [$value]);
            }

            $query = Person::withTrashed();
            $user = request()->user();

            if ($user instanceof User) {
                $query = CrmVisibility::people($query, $user);
            }

            return $query->findOrFail($value);
        });

        // An affiliation is reachable through the entreprise it belongs to.
        Route::bind('affiliation', function (string $value): Affiliation {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Affiliation::class, [$value]);
            }

            $query = Affiliation::query();
            $user = request()->user();

            if ($user instanceof User) {
                $query->whereIn('organisation_id', CrmVisibility::organisations(Organisation::withTrashed(), $user)->select('organisations.id'));
            }

            return $query->findOrFail($value);
        });

        // A note / attachment is reachable through the fiche it belongs to.
        Route::bind('note', fn (string $value): Note => self::bindOnSubject(Note::query(), $value, Note::class));
        Route::bind('attachment', fn (string $value): Attachment => self::bindOnSubject(Attachment::query(), $value, Attachment::class));
    }

    /**
     * @template TModel of Note|Attachment
     *
     * @param  Builder<TModel>  $query
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private static function bindOnSubject(Builder $query, string $value, string $model): Note|Attachment
    {
        if (! Str::isUuid($value)) {
            throw (new ModelNotFoundException)->setModel($model, [$value]);
        }

        $user = request()->user();

        if ($user instanceof User && CrmVisibility::restricts($user)) {
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $o) => $o->where('notable_type', (new Organisation)->getMorphClass())
                    ->whereIn('notable_id', CrmVisibility::organisations(Organisation::withTrashed(), $user)->select('organisations.id')))
                ->orWhere(fn (Builder $p) => $p->where('notable_type', (new Person)->getMorphClass())
                    ->whereIn('notable_id', CrmVisibility::people(Person::withTrashed(), $user)->select('people.id'))));
        }

        return $query->findOrFail($value);
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
        ReferenceUsage::register(ReferenceList::ProfessionalSituation, 'people', 'professional_situation_id');
        ReferenceUsage::register(ReferenceList::FundingMode, 'people', 'funding_mode_id');
        ReferenceUsage::register(ReferenceList::ContactOrigin, 'people', 'origin_id');
        ReferenceUsage::register(ReferenceList::Das, 'person_das', 'das_id');
        ReferenceUsage::register(ReferenceList::DecisionRole, 'affiliations', 'decision_role_id');
    }
}
