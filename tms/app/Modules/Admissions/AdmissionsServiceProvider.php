<?php

namespace App\Modules\Admissions;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\CustomFields\CustomFieldDefinition;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\DossierBlocks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            );
        }

        MorphAliases::register([
            Candidature::MORPH_ALIAS => Candidature::class,
            CandidatureFile::MORPH_ALIAS => CandidatureFile::class,
            AlternanceContract::MORPH_ALIAS => AlternanceContract::class,
            CustomFieldDefinition::MORPH_ALIAS => CustomFieldDefinition::class,
        ]);
    }

    public function boot(): void
    {
        self::registerReferenceUsage();
        self::registerTrainingUsage();

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
        });

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
    }

    public static function registerReferenceUsage(): void
    {
        ReferenceUsage::register(ReferenceList::Das, 'candidatures', 'das_id');
        ReferenceUsage::register(ReferenceList::ContactOrigin, 'candidatures', 'origin_id');
        ReferenceUsage::register(ReferenceList::CandidatureRefusalReason, 'candidatures', 'decision_reason_id');
    }

    public static function registerTrainingUsage(): void
    {
        TrainingUsage::register('candidatures', fn (string $trainingId): bool => Candidature::withTrashed()->where('training_id', $trainingId)->exists());
    }
}
