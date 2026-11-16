<?php

namespace App\Modules\Sessions\Enrolments;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\DuplicateTrainee;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Enrolment screens (session and dossier pages): search and creation of a
 * trainee, enrolment, confirmation, promotion from the waiting list,
 * abandon, cancellation and the details of an enrolment. Rights:
 * sessions.sessions.update on the session (SessionPolicy, EnrolmentPolicy);
 * the dossier must be one the user sees (sessions.dossiers.view).
 */
class EnrolmentController extends Controller
{
    /** Route action => method of Enrolments. */
    public const ACTIONS = ['confirm' => 'confirm', 'promote' => 'promote', 'cancel' => 'cancel'];

    public function __construct(private readonly Enrolments $enrolments) {}

    /**
     * People of the whole organisme, identity only (name, first name, birth
     * date, current entreprise), the employees of « organisation » first:
     * a user limited to his DAS finds the existing person instead of
     * creating a duplicate (spec lot 2a § 9).
     */
    public function trainees(Request $request, TrainingSession $trainingSession, Trainees $trainees): JsonResponse
    {
        Gate::authorize('update', $trainingSession);
        $q = $request->query('q');
        $organisation = $request->query('organisation');

        return response()->json(['personnes' => is_string($q) && mb_strlen(trim($q)) >= 2
            ? $trainees->search(mb_substr($q, 0, 100), is_string($organisation) ? $organisation : null)
            : []])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Creates the trainee as an ordinary person of the CRM, given the DAS of
     * the training of the session; similar people found: 409 with them
     * (identity only), created anyway with confirm_duplicate.
     */
    public function createTrainee(Request $request, TrainingSession $trainingSession, Trainees $trainees, Trainings $trainings, Fiches $fiches): JsonResponse
    {
        Gate::authorize('update', $trainingSession);
        $das = $trainings->find($trainingSession->training_id)?->das_id;

        try {
            $id = $trainees->create([
                ...$request->only(['civility', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'postal_code', 'city', 'birth_date', 'organisation_id']),
                'das_ids' => $das === null ? [] : [$das],
                'confirm_duplicate' => $request->boolean('confirm_duplicate'),
            ]);
        } catch (DuplicateTrainee $e) {
            return response()->json(['message' => $e->getMessage(), 'candidates' => $e->candidates], 409);
        }

        return response()->json(['personne' => ['id' => $id, 'label' => $fiches->person($id)['label'] ?? '']], 201);
    }

    public function store(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('update', $trainingSession);
        $data = Validator::make($request->all(), [
            'dossier_id' => ['required', 'uuid'],
            'dossier_line_id' => ['required', 'uuid'],
            'person_id' => ['required', 'uuid'],
        ], [
            'dossier_id.*' => 'Choisissez le dossier.',
            'dossier_line_id.*' => 'Choisissez la ligne de formation du dossier.',
            'person_id.*' => 'Choisissez le stagiaire.',
        ])->validate();

        $dossier = SessionsVisibility::dossiers(Dossier::query(), $user)->find($data['dossier_id']);
        if (! $dossier instanceof Dossier) {
            throw ValidationException::withMessages(['dossier_id' => 'Dossier introuvable.']);
        }
        $line = DossierLine::query()->where('dossier_id', $dossier->id)->find($data['dossier_line_id']);
        if (! $line instanceof DossierLine) {
            throw ValidationException::withMessages(['dossier_line_id' => 'Ligne du dossier introuvable.']);
        }

        $enrolment = $this->enrolments->enrol($dossier, $line, $trainingSession, $data['person_id'], $request->only(['entered_on', 'bpf_category_id', 'adaptation_needs', 'notes']));

        return back()->with('status', $enrolment->status === EnrolmentStatus::Waitlisted
            ? "Session complète : stagiaire placé en liste d'attente (position {$enrolment->waitlist_position})."
            : 'Stagiaire inscrit.');
    }

    public function update(Request $request, Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('update', $enrolment);

        $this->enrolments->update($enrolment, $request->only(['entered_on', 'bpf_category_id', 'adaptation_needs', 'notes']));

        return back()->with('status', 'Inscription enregistrée.');
    }

    /**
     * Confirm, promote from the waiting list, cancel.
     */
    public function transition(Enrolment $enrolment, string $action): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $method = self::ACTIONS[$action];

        $this->enrolments->{$method}($enrolment);

        return back()->with('status', match ($action) {
            'confirm' => 'Inscription confirmée.',
            'promote' => 'Stagiaire promu de la liste d\'attente.',
            default => 'Inscription annulée.',
        });
    }

    /**
     * Abandon: a leaving day and a reason, both required.
     */
    public function abandon(Request $request, Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $data = Validator::make($request->all(), [
            'left_on' => ['bail', 'required', 'date_format:Y-m-d'],
            'abandon_reason_id' => ['bail', 'required', 'uuid'],
        ], [
            'left_on.required' => 'Indiquez la date de sortie.',
            'left_on.*' => 'Date de sortie invalide.',
            'abandon_reason_id.required' => 'Choisissez le motif d\'abandon.',
            'abandon_reason_id.*' => 'Motif d\'abandon inconnu.',
        ])->validate();
        // A Paris day, already checked Y-m-d.
        $leftOn = CarbonImmutable::parse($data['left_on'], 'Europe/Paris')->startOfDay();

        $this->enrolments->abandon($enrolment, $leftOn, $data['abandon_reason_id']);

        return back()->with('status', 'Abandon enregistré.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
