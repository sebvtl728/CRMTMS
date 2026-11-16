<?php

namespace App\Modules\Sessions\Trainers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\DuplicateTrainee;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Formateurs » (sessions.trainers.manage): the trainers of the organisme,
 * declared from an existing person of the CRM or a new one (Crm\Public),
 * their status, account, unavailabilities. {trainer} is a trainer of the
 * organisme out of the trash (RLS), 404 otherwise.
 */
class TrainerController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('sessions/trainers/index', [
            'trainers' => TrainerRows::list(),
            'statusOptions' => TrainerStatus::options(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * People of the whole organisme (identity only) to declare trainer.
     */
    public function people(Request $request, Trainees $trainees): JsonResponse
    {
        $q = $request->query('q');

        return response()->json(['personnes' => is_string($q) && mb_strlen(trim($q)) >= 2 ? $trainees->search(mb_substr($q, 0, 100)) : []])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Trainees $trainees): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TrainerStatus::class)],
            'person_id' => ['required_without:person', 'nullable', 'string'],
            'person' => ['nullable', 'array'],
        ], [
            'person_id.required_without' => 'Choisissez une personne ou créez-en une.',
            'status.required' => 'Choisissez « Salarié » ou « Indépendant ».',
            'status.enum' => 'Choisissez « Salarié » ou « Indépendant ».',
            'person.array' => 'Indiquez la nouvelle personne.',
        ]);
        $status = TrainerStatus::from((string) $data['status']);
        $personId = $data['person_id'] ?? null;
        $person = $data['person'] ?? null;
        if (! is_string($personId) && ! is_array($person)) {
            throw ValidationException::withMessages(['person_id' => 'Choisissez une personne ou créez-en une.']);
        }

        $trainer = DB::transaction(function () use ($personId, $person, $status, $trainees, $request): Trainer {
            if (is_array($person)) {
                $personId = $this->newPerson($trainees, $person, $request->boolean('confirm_duplicate'));
            }

            return Trainers::declare((string) $personId, $status);
        });

        return to_route('trainers.show', $trainer->id)->with('status', 'Formateur ajouté.');
    }

    public function show(Request $request, Trainer $trainer, Fiches $fiches): Response
    {
        $viewer = $request->user();
        assert($viewer instanceof User);
        $label = $fiches->personLabels([$trainer->person_id])[$trainer->person_id]['label'] ?? 'Personne introuvable';
        $email = $fiches->personEmails([$trainer->person_id])[$trainer->person_id] ?? null;
        $account = $trainer->user_id === null ? null : User::query()->find($trainer->user_id);
        $linked = Trainer::withTrashed()->whereNotNull('user_id')->whereKeyNot($trainer->id)->pluck('user_id')->all();

        return Inertia::render('sessions/trainers/show', [
            'trainer' => [
                'id' => $trainer->id,
                'label' => $label,
                'email' => $email,
                'status' => $trainer->status,
                'status_label' => $trainer->statusLabel(),
                'active' => $trainer->active,
                'user' => $account === null ? null : ['id' => $account->id, 'name' => $account->name, 'email' => $account->email, 'active' => $account->isActive()],
                'fiche_url' => isset($fiches->openable($viewer, 'person', [$trainer->person_id])[$trainer->person_id]) ? "/crm/people/{$trainer->person_id}" : null,
            ],
            'unavailabilities' => UnavailabilityInput::rows($trainer->unavailabilities()->orderByDesc('starts_at')->get()),
            'statusOptions' => TrainerStatus::options(),
            // Accounts of the organisme not linked to another trainer.
            'userOptions' => User::query()->whereNotIn('id', $linked)->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $u): array => ['value' => $u->id, 'label' => "{$u->name} ({$u->email})"])->values()->all(),
            // The invitation of users.manage, prefilled with the Formateur role.
            'invite_url' => $trainer->user_id === null && $email !== null && $viewer->can(CorePermissions::USERS_MANAGE)
                ? '/admin/users/invite?'.http_build_query(['email' => $email, 'name' => $label, 'role' => Role::Formateur->value])
                : null,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TrainerStatus::class)],
            'active' => ['required', 'boolean'],
        ], [
            'status.required' => 'Choisissez « Salarié » ou « Indépendant ».',
            'status.enum' => 'Choisissez « Salarié » ou « Indépendant ».',
            'active.required' => 'Indiquez si le formateur est actif.',
            'active.boolean' => 'Indiquez si le formateur est actif.',
        ]);
        Trainers::update($trainer, TrainerStatus::from((string) $data['status']), $request->boolean('active'));

        return back()->with('status', 'Formateur enregistré.');
    }

    public function destroy(Trainer $trainer): RedirectResponse
    {
        Trainers::delete($trainer);

        return to_route('trainers.index')->with('status', 'Formateur supprimé.');
    }

    /**
     * Links (or unlinks) the account of the organisme by hand.
     */
    public function link(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'uuid']], ['user_id.uuid' => "Ce compte n'existe pas."]);
        $user = null;
        if (isset($data['user_id'])) {
            $user = User::query()->find($data['user_id']) ?? throw ValidationException::withMessages(['user_id' => "Ce compte n'existe pas."]);
        }
        Trainers::link($trainer, $user);

        return back()->with('status', $user === null ? 'Compte délié du formateur.' : "Compte {$user->email} lié au formateur.");
    }

    public function storeUnavailability(Request $request, Trainer $trainer): RedirectResponse
    {
        $input = UnavailabilityInput::from($request);
        Trainers::addUnavailability($trainer, $input['from'], $input['to'], $input['reason']);

        return back()->with('status', 'Indisponibilité enregistrée.');
    }

    public function destroyUnavailability(Trainer $trainer, TrainerUnavailability $unavailability): RedirectResponse
    {
        abort_if($unavailability->trainer_id !== $trainer->id, 404);
        Trainers::removeUnavailability($unavailability);

        return back()->with('status', 'Indisponibilité supprimée.');
    }

    /**
     * Creates the person in the CRM (Crm\Public\Trainees); errors on the
     * fields of « person.* », similar people as an error on its name.
     *
     * @param  array<mixed>  $person
     */
    private function newPerson(Trainees $trainees, array $person, bool $confirmDuplicate): string
    {
        $fields = array_intersect_key($person, array_flip(['civility', 'first_name', 'last_name', 'email', 'phone', 'mobile']));
        try {
            return $trainees->create([...$fields, 'confirm_duplicate' => $confirmDuplicate]);
        } catch (DuplicateTrainee $e) {
            $names = implode(', ', array_map(fn (array $c) => trim(($c['first_name'] ?? '').' '.$c['last_name']), $e->candidates));
            throw ValidationException::withMessages(['person.last_name' => "Une personne semblable existe déjà ({$names}) : choisissez-la dans la recherche, ou confirmez la création."]);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors["person.{$field}"] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
