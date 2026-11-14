<?php

namespace App\Modules\Crm;

use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\ActivitiesProvider;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\ActivityPolicy;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Gdpr\Consent;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportProfile;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportStamp;
use App\Modules\Crm\Interests\FicheTraining;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Opportunities\OpportunitiesProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityPolicy;
use App\Modules\Crm\Opportunities\OpportunityTraining;
use App\Modules\Crm\Opportunities\StageChange;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationPolicy;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PersonPolicy;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteLine;
use App\Modules\Crm\Quotes\QuotePolicy;
use App\Modules\Crm\Quotes\QuotesProvider;
use App\Modules\Crm\Search\SavedFilter;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\AttachmentsProvider;
use App\Modules\Crm\Timeline\AuditProvider;
use App\Modules\Crm\Timeline\LastContact;
use App\Modules\Crm\Timeline\Note;
use App\Modules\Crm\Timeline\NotesProvider;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Visibility\CrmVisibility;
use App\Modules\Crm\Visibility\FollowedDas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
            'consent' => Consent::class,
            'opportunity' => Opportunity::class,
            'opportunity_stage_change' => StageChange::class,
            'opportunity_training' => OpportunityTraining::class,
            'fiche_training' => FicheTraining::class,
            'activity' => Activity::class,
            'quote' => Quote::class,
            'quote_line' => QuoteLine::class,
        ]);

        // Sources of the « Historique »; later increments tag their own.
        $this->app->tag([NotesProvider::class, AttachmentsProvider::class, AuditProvider::class, OpportunitiesProvider::class, ActivitiesProvider::class, QuotesProvider::class], Timeline::TAG);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();
        self::registerLinkedData();
        self::registerTrainingUsage();
        self::registerContactSources();
        FollowedDas::registerOnUsersPage();
        Event::listen(PrimaryRoleChanged::class, FollowedDas::onRoleChanged(...));

        // Check + download count together: 10 exports a minute per user.
        RateLimiter::for('crm-export', fn (Request $request) => Limit::perMinute(20)->by('crm-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(['message' => 'Trop d\'exports en peu de temps. Réessayez dans une minute.'], 429, $headers)));

        // Quote e-mails (send, reminder, « Renvoyer »): 20 a minute per user.
        RateLimiter::for('quote-mail', fn (Request $request) => Limit::perMinute(20)->by('quote-mail:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // A draft's PDF is rendered on each request (Gotenberg): 30 a minute per user.
        // Signed copy of a quote (upload, download): 30 a minute per user.
        RateLimiter::for('quote-signed', fn (Request $request) => Limit::perMinute(30)->by('quote-signed:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('quote-pdf', fn (Request $request) => Limit::perMinute(30)->by('quote-pdf:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Gate::policy(Organisation::class, OrganisationPolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);
        Gate::policy(Opportunity::class, OpportunityPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);

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

            return self::unlessTrashedForbidden($query->findOrFail($value), $user);
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

            return self::unlessTrashedForbidden($query->findOrFail($value), $user);
        });

        // An affiliation is reachable through the entreprise it belongs to.
        Route::bind('affiliation', function (string $value): Affiliation {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Affiliation::class, [$value]);
            }

            $query = Affiliation::query();
            $user = request()->user();

            if ($user instanceof User) {
                // Trashed entreprises: only with crm.delete, like the fiche itself.
                $query->whereIn('organisation_id', CrmVisibility::organisations(self::organisationsFor($user), $user)->select('organisations.id'));
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

        // An opportunity: DAS visibility; on a trashed fiche only with crm.delete.
        Route::bind('opportunity', function (string $value): Opportunity {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Opportunity::class, [$value]);
            }

            $user = request()->user();
            $query = Opportunity::query();
            if ($user instanceof User) {
                $query = CrmVisibility::opportunities($query, $user);
            }
            $opportunity = $query->with(['organisation', 'person'])->findOrFail($value);

            $fiche = $opportunity->organisation ?? $opportunity->person;
            if ($user instanceof User && ($fiche === null || $fiche->trashed()) && ! $user->can(Permissions::DELETE)) {
                throw (new ModelNotFoundException)->setModel(Opportunity::class, [$value]);
            }

            return $opportunity;
        });

        // A quote: visible with its opportunity (its DAS), which must not be
        // trashed; on a trashed fiche only with crm.delete, like the opportunity.
        Route::bind('quote', function (string $value): Quote {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Quote::class, [$value]);
            }

            $user = request()->user();
            $query = Quote::query();
            if ($user instanceof User) {
                $query = CrmVisibility::quotes($query, $user);
            }
            $quote = $query->with(['company', 'opportunity.organisation', 'opportunity.person'])->findOrFail($value);

            $opportunity = $quote->opportunity;
            $fiche = $opportunity->organisation ?? $opportunity->person;
            if ($opportunity->trashed()
                || ($user instanceof User && ($fiche === null || $fiche->trashed()) && ! $user->can(Permissions::DELETE))) {
                throw (new ModelNotFoundException)->setModel(Quote::class, [$value]);
            }

            return $quote;
        });

        // An action: visible with its opportunity when it has one (its DAS),
        // otherwise with one of its fiches; on a trashed fiche only with crm.delete.
        Route::bind('activity', function (string $value): Activity {
            if (! Str::isUuid($value)) {
                throw (new ModelNotFoundException)->setModel(Activity::class, [$value]);
            }

            $user = request()->user();
            $query = Activity::query();
            if ($user instanceof User) {
                $query = CrmVisibility::activities($query, $user);
            }
            $activity = $query->with(['organisation', 'person', 'opportunity.organisation', 'opportunity.person'])->findOrFail($value);

            if ($user instanceof User && $activity->ficheTrashed() && ! $user->can(Permissions::DELETE)) {
                throw (new ModelNotFoundException)->setModel(Activity::class, [$value]);
            }

            return $activity;
        });

        // A note / attachment is reachable through the fiche it belongs to.
        Route::bind('note', fn (string $value): Note => self::bindOnSubject(Note::query(), $value, Note::class));
        Route::bind('attachment', fn (string $value): Attachment => self::bindOnSubject(Attachment::query(), $value, Attachment::class));
    }

    /**
     * @return Builder<Organisation>
     */
    private static function organisationsFor(User $user): Builder
    {
        return $user->can(Permissions::DELETE) ? Organisation::withTrashed() : Organisation::query();
    }

    /**
     * @return Builder<Person>
     */
    private static function peopleFor(User $user): Builder
    {
        return $user->can(Permissions::DELETE) ? Person::withTrashed() : Person::query();
    }

    /**
     * A trashed fiche is readable by URL only with crm.delete, like the
     * « corbeille » filter of the lists: anybody else gets a 404.
     *
     * @template TModel of Organisation|Person
     *
     * @param  TModel  $fiche
     * @return TModel
     */
    private static function unlessTrashedForbidden(Organisation|Person $fiche, mixed $user): Organisation|Person
    {
        if ($fiche->trashed() && $user instanceof User && ! $user->can(Permissions::DELETE)) {
            throw (new ModelNotFoundException)->setModel($fiche::class, [$fiche->getKey()]);
        }

        return $fiche;
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

        // Subjects: the fiches the user sees, trashed ones only with crm.delete.
        if ($user instanceof User && (CrmVisibility::restricts($user) || ! $user->can(Permissions::DELETE))) {
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $o) => $o->where('notable_type', (new Organisation)->getMorphClass())
                    ->whereIn('notable_id', CrmVisibility::organisations(self::organisationsFor($user), $user)->select('organisations.id')))
                ->orWhere(fn (Builder $p) => $p->where('notable_type', (new Person)->getMorphClass())
                    ->whereIn('notable_id', CrmVisibility::people(self::peopleFor($user), $user)->select('people.id'))));
        }

        return $query->findOrFail($value);
    }

    /**
     * Data hanging on a fiche (LinkedData): an import undo keeps the fiche.
     * Consents are never created by an import: any consent of the person was
     * recorded since, and must stay with her fiche (proof, RGPD).
     */
    public static function registerLinkedData(): void
    {
        $except = fn (Builder $query, array $ids): Builder => $ids === [] ? $query : $query->whereKeyNot($ids);

        LinkedData::register('crm.affiliations', fn (Organisation|Person $fiche, array $ids): bool => $except(
            Affiliation::query()->where($fiche instanceof Organisation ? 'organisation_id' : 'person_id', $fiche->getKey()), $ids)->exists());
        LinkedData::register('crm.notes', fn (Organisation|Person $fiche, array $ids): bool => $except(
            Note::query()->where('notable_type', $fiche->getMorphClass())->where('notable_id', $fiche->getKey()), $ids)->exists());
        LinkedData::register('crm.attachments', fn (Organisation|Person $fiche, array $ids): bool => $except(
            Attachment::query()->where('notable_type', $fiche->getMorphClass())->where('notable_id', $fiche->getKey()), $ids)->exists());
        LinkedData::register('crm.opportunities', fn (Organisation|Person $fiche, array $ids): bool => $except(
            Opportunity::withTrashed()->where($fiche instanceof Organisation ? 'organisation_id' : 'person_id', $fiche->getKey()), $ids)->exists()
            || ($fiche instanceof Person && DB::table('opportunity_contacts')->where('person_id', $fiche->getKey())->exists()));
        LinkedData::register('crm.activities', fn (Organisation|Person $fiche, array $ids): bool => $except(
            Activity::withTrashed()->where($fiche instanceof Organisation ? 'organisation_id' : 'person_id', $fiche->getKey()), $ids)->exists());
        // A quote names its account and its recipient (an interlocuteur too).
        LinkedData::register('crm.quotes', fn (Organisation|Person $fiche, array $ids): bool => $except(
            Quote::withTrashed()->where(fn (Builder $q) => $fiche instanceof Organisation
                ? $q->where('organisation_id', $fiche->getKey())
                : $q->where('person_id', $fiche->getKey())->orWhere('contact_person_id', $fiche->getKey())), $ids)->exists());
        LinkedData::register('crm.interest_trainings', fn (Organisation|Person $fiche, array $ids): bool => $except(
            FicheTraining::query()->where(FicheTrainings::column($fiche), $fiche->getKey()), $ids)->exists());
        LinkedData::register('crm.consents', fn (Organisation|Person $fiche, array $ids): bool => $fiche instanceof Person
            && $except(Consent::query()->where('person_id', $fiche->getKey()), $ids)->exists());
    }

    /**
     * A training on an opportunity or a quote (a trashed one too: its lines
     * stay), or of interest to a fiche, is in use: the catalogue refuses to delete it.
     */
    public static function registerTrainingUsage(): void
    {
        TrainingUsage::register('opportunities', fn (string $trainingId): bool => OpportunityTraining::query()->where('training_id', $trainingId)->exists());
        // « Formations d'intérêt » of an entreprise or a person.
        TrainingUsage::register('fiches', fn (string $trainingId): bool => FicheTrainings::uses($trainingId));
        // Lines of a trashed quote stay too.
        TrainingUsage::register('quotes', fn (string $trainingId): bool => QuoteLine::query()->where('training_id', $trainingId)->exists());
    }

    /**
     * Done actions are contacts (LastContact::recompute): those on the fiches
     * and on their opportunities. Trashed actions no longer count.
     */
    public static function registerContactSources(): void
    {
        LastContact::registerSource(function (string $subjectType, array $ids): ?CarbonInterface {
            $column = match ($subjectType) {
                (new Organisation)->getMorphClass() => 'organisation_id',
                (new Person)->getMorphClass() => 'person_id',
                default => null,
            };
            if ($column === null) {
                return null;
            }

            $latest = Activity::query()->whereNotNull('done_at')
                ->where(fn (Builder $q) => $q->whereIn($column, $ids)
                    ->orWhereIn('opportunity_id', DB::table('opportunities')->select('id')->whereIn($column, $ids)))
                ->max('done_at');

            return is_string($latest) ? CarbonImmutable::parse($latest) : null;
        });

        // A quote sent or reminded: a contact of its account and its recipient.
        LastContact::registerSource(function (string $subjectType, array $ids): ?CarbonInterface {
            $query = match ($subjectType) {
                (new Organisation)->getMorphClass() => Quote::query()->whereIn('organisation_id', $ids),
                (new Person)->getMorphClass() => Quote::query()->where(fn (Builder $q) => $q->whereIn('person_id', $ids)->orWhereIn('contact_person_id', $ids)),
                default => null,
            };
            if ($query === null) {
                return null;
            }

            $latest = $query->whereNotNull('sent_at')->toBase()
                ->selectRaw('max(greatest(sent_at, coalesce(reminded_at, sent_at))) as latest')->value('latest');

            return is_string($latest) ? CarbonImmutable::parse($latest) : null;
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
        ReferenceUsage::register(ReferenceList::ProfessionalSituation, 'people', 'professional_situation_id');
        ReferenceUsage::register(ReferenceList::FundingMode, 'people', 'funding_mode_id');
        ReferenceUsage::register(ReferenceList::ContactOrigin, 'people', 'origin_id');
        ReferenceUsage::register(ReferenceList::Das, 'person_das', 'das_id');
        ReferenceUsage::register(ReferenceList::DecisionRole, 'affiliations', 'decision_role_id');
        ReferenceUsage::register(ReferenceList::PipelineStage, 'opportunities', 'stage_id');
        ReferenceUsage::register(ReferenceList::PipelineStage, 'opportunity_stage_changes', 'from_stage_id');
        ReferenceUsage::register(ReferenceList::PipelineStage, 'opportunity_stage_changes', 'to_stage_id');
        ReferenceUsage::register(ReferenceList::LossReason, 'opportunity_stage_changes', 'loss_reason_id');
        ReferenceUsage::register(ReferenceList::Das, 'opportunities', 'das_id');
        ReferenceUsage::register(ReferenceList::Priority, 'opportunities', 'priority_id');
        ReferenceUsage::register(ReferenceList::FundingMode, 'opportunities', 'funding_mode_id');
        ReferenceUsage::register(ReferenceList::LossReason, 'opportunities', 'loss_reason_id');
        ReferenceUsage::register(ReferenceList::ActionType, 'activities', 'type_id');
        ReferenceUsage::register(ReferenceList::ActionResult, 'activities', 'result_id');
        ReferenceUsage::register(ReferenceList::Das, 'quote_lines', 'das_id');
    }
}
