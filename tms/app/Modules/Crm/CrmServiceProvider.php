<?php

namespace App\Modules\Crm;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportProfile;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportStamp;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationPolicy;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PersonPolicy;
use App\Modules\Crm\Search\SavedFilter;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\AttachmentsProvider;
use App\Modules\Crm\Timeline\AuditProvider;
use App\Modules\Crm\Timeline\Note;
use App\Modules\Crm\Timeline\NotesProvider;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
            'saved_filter' => SavedFilter::class,
            'import_profile' => ImportProfile::class,
            'import_batch' => ImportBatch::class,
            'import_row' => ImportRow::class,
            'import_stamp' => ImportStamp::class,
        ]);

        // Sources of the « Historique »; later increments tag their own.
        $this->app->tag([NotesProvider::class, AttachmentsProvider::class, AuditProvider::class], Timeline::TAG);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();

        // Check + download count together: 10 exports a minute per user.
        RateLimiter::for('crm-export', fn (Request $request) => Limit::perMinute(20)->by('crm-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(['message' => 'Trop d\'exports en peu de temps. Réessayez dans une minute.'], 429, $headers)));

        Gate::policy(Organisation::class, OrganisationPolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);

        // A fiche the user may not see is a 404, whatever the action. Trashed
        // fiches resolve too (show, restore): the controller decides.
        Route::bind('organisation', function (string $value): Organisation {
            // A malformed id is a plain 404 (PostgreSQL would raise on the uuid cast).
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Organisation::class, [$value]);
            }

            $user = request()->user();

            // A merged fiche is resolved before the visibility filter (it has
            // lost its DAS and links): only the final kept fiche must be visible.
            self::redirectIfMerged(Organisation::withTrashed()->whereKey($value)->whereNotNull('merged_into_id')->first(), $value,
                fn () => $user instanceof User ? CrmVisibility::organisations(Organisation::withTrashed(), $user) : Organisation::withTrashed(), 'crm.organisations.show');

            $query = Organisation::withTrashed();
            if ($user instanceof User) {
                $query = CrmVisibility::organisations($query, $user);
            }

            return $query->findOrFail($value);
        });

        Route::bind('person', function (string $value): Person {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Person::class, [$value]);
            }

            $user = request()->user();

            // A merged fiche is resolved before the visibility filter (it has
            // lost its DAS and links): only the final kept fiche must be visible.
            self::redirectIfMerged(Person::withTrashed()->whereKey($value)->whereNotNull('merged_into_id')->first(), $value,
                fn () => $user instanceof User ? CrmVisibility::people(Person::withTrashed(), $user) : Person::withTrashed(), 'crm.people.show');

            $query = Person::withTrashed();
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

        // A saved filter is reachable when it is the user's own or shared.
        Route::bind('savedFilter', function (string $value): SavedFilter {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(SavedFilter::class, [$value]);
            }

            $user = request()->user();
            $query = SavedFilter::query();

            return ($user instanceof User ? $query->visibleTo($user) : $query)->findOrFail($value);
        });

        // A note / attachment is reachable through the fiche it belongs to.
        Route::bind('note', fn (string $value): Note => self::bindOnSubject(Note::query(), $value, Note::class));
        Route::bind('attachment', fn (string $value): Attachment => self::bindOnSubject(Attachment::query(), $value, Attachment::class));
    }

    /**
     * A merged fiche is never acted upon: every visit goes to the fiche that
     * absorbed it, following successive merges without the visibility filter
     * (the absorbed fiches lost their DAS and links), the final kept fiche
     * must be visible (404 otherwise). A GET keeps its path (fiche, edit
     * form, timeline…); any other method gets a 303 to the kept fiche (seen
     * as a GET: a DELETE is never replayed on it).
     *
     * @template TModel of Organisation|Person
     *
     * @param  TModel|null  $merged
     * @param  callable(): Builder<TModel>  $visible
     */
    private static function redirectIfMerged(Organisation|Person|null $merged, string $value, callable $visible, string $showRoute): void
    {
        if ($merged === null || $merged->merged_into_id === null) {
            return;
        }

        $target = $merged;
        for ($hops = 0; $hops < 20 && $target !== null && $target->merged_into_id !== null; $hops++) {
            $target = $target::withTrashed()->find($target->merged_into_id);
        }

        if ($target === null || $target->merged_into_id !== null || ! $visible()->whereKey($target->getKey())->exists()) {
            throw (new ModelNotFoundException)->setModel($merged::class, [$value]);
        }

        $request = request();
        $response = $request->isMethod('GET') || $request->isMethod('HEAD')
            ? redirect()->to(str_replace($value, (string) $target->getKey(), $request->getRequestUri()))
            : redirect()->route($showRoute, $target->getKey(), 303);

        throw new HttpResponseException($response);
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
