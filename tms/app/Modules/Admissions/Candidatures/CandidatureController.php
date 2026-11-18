<?php

namespace App\Modules\Admissions\Candidatures;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Alternance\ContractType;
use App\Modules\Admissions\CustomFields\CustomFieldValues;
use App\Modules\Admissions\Permissions;
use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page, creation, change, trash and restore of a candidature. The route
 * binding (AdmissionsServiceProvider) already answers 404 outside the view
 * scope; each write checks the scope of its own right.
 */
class CandidatureController extends Controller
{
    public function create(Request $request): Response
    {
        $personId = (string) $request->query('person', '');
        $person = $personId !== '' ? app(Fiches::class)->person($personId) : null;

        return Inertia::render('admissions/candidatures/create', [
            'custom_fields' => CustomFieldValues::forPage(new Candidature),
            'person' => $person === null ? null : ['id' => $person['id'], 'label' => $person['label']],
            'options' => self::options(null),
        ]);
    }

    public function store(Request $request, CandidatureWriter $writer): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->all();
        $dasId = $data['das_id'] ?? (is_string($data['training_id'] ?? null) ? app(Trainings::class)->find($data['training_id'])?->das_id : null);
        if (is_string($dasId) && ! AdmissionsVisibility::allowsDas($user, Permissions::CANDIDATURES_UPDATE, $dasId)) {
            abort(403);
        }
        $candidature = $writer->create($data, $user);

        return to_route('admissions.candidatures.show', $candidature)->with('status', 'Candidature créée.');
    }

    public function show(Request $request, Candidature $candidature): Response
    {
        $user = self::user($request);

        return Inertia::render('admissions/candidatures/show', [
            'candidature' => CandidaturePresenter::show($candidature, $user),
            'custom_fields' => CustomFieldValues::forPage($candidature),
            'can' => [
                'update' => AdmissionsVisibility::allows($user, Permissions::CANDIDATURES_UPDATE, $candidature) && ! $candidature->trashed(),
                'delete' => AdmissionsVisibility::allows($user, Permissions::CANDIDATURES_DELETE, $candidature),
                'sensitive' => AdmissionsVisibility::sensitive($user),
                'create_dossier' => $candidature->status === CandidatureStatus::Admitted && ! $candidature->trashed()
                    && ($candidature->dossier_id === null || Admission::dossierState($candidature->dossier_id) !== 'live')
                    && AdmissionsVisibility::allows($user, Permissions::CANDIDATURES_UPDATE, $candidature)
                    && Admission::mayCreateDossier($user, $candidature->das_id),
            ],
            'options' => self::options($candidature),
        ]);
    }

    public function update(Request $request, Candidature $candidature, CandidatureWriter $writer): RedirectResponse
    {
        $user = self::user($request);
        self::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        $writer->update($candidature, $request->all(), $user);

        return back()->with('status', 'Candidature enregistrée.');
    }

    public function destroy(Request $request, Candidature $candidature): RedirectResponse
    {
        self::authorizeOn(self::user($request), Permissions::CANDIDATURES_DELETE, $candidature);
        $candidature->delete();

        return to_route('admissions.candidatures.index')->with('status', 'Candidature mise à la corbeille.');
    }

    public function restore(Request $request, Candidature $candidature): RedirectResponse
    {
        self::authorizeOn(self::user($request), Permissions::CANDIDATURES_DELETE, $candidature);
        $candidature->restore();

        return to_route('admissions.candidatures.show', $candidature)->with('status', 'Candidature restaurée.');
    }

    public static function authorizeOn(User $user, string $permission, Candidature $candidature): void
    {
        if (! AdmissionsVisibility::allows($user, $permission, $candidature)) {
            abort(403);
        }
    }

    public static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Select options of the candidature forms.
     *
     * @return array<string, mixed>
     */
    public static function options(?Candidature $candidature): array
    {
        $items = fn (ReferenceList $list) => ReferenceItem::options($list)->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->values()->all();

        return [
            'statuses' => CandidatureStatus::options(),
            'contract_types' => ContractType::options(),
            'refusal_reasons' => $items(ReferenceList::CandidatureRefusalReason),
            'das' => $items(ReferenceList::Das),
            'origins' => $items(ReferenceList::ContactOrigin),
            'education_levels' => $items(ReferenceList::EducationLevel),
            'situations' => $items(ReferenceList::ProfessionalSituation),
            'users' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all(),
            'training_options' => array_map(fn (TrainingData $t) => ['value' => $t->id, 'label' => $t->title, 'rncp_code' => $t->rncp_code], app(Trainings::class)->options()),
            'sessions' => $candidature?->training_id !== null ? Admission::sessionOptions($candidature->training_id) : [],
        ];
    }
}
