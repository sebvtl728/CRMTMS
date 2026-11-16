<?php

namespace App\Modules\Sessions\Trainers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Formateurs » (sessions.trainers.manage): the trainers of the organisme,
 * TMS users or trainers without account (the CRM person behind them is
 * internal: Trainers, Crm\Public\Fiches),
 * their status, account, unavailabilities. {trainer} is a trainer of the
 * organisme out of the trash (RLS), 404 otherwise.
 */
class TrainerController extends Controller
{
    private const NOT_FOLLOWED = 'Vous ne pouvez gérer que les DAS que vous suivez.';

    public function index(Request $request): Response
    {
        // ?das=: the trainers attached to that DAS (an unknown value: no filter).
        $das = $request->query('das');
        $das = is_string($das) && Str::isUuid($das) && ReferenceItem::query()->where('list', ReferenceList::Das)->whereKey($das)->exists() ? $das : null;
        $viewer = $request->user();
        assert($viewer instanceof User);

        return Inertia::render('sessions/trainers/index', [
            'trainers' => TrainerRows::list($viewer, $das),
            'das' => $das,
            'dasOptions' => SessionListQuery::reference(ReferenceList::Das, array_filter([$das])),
            'statusOptions' => TrainerStatus::options(),
            'userOptions' => TrainerRows::userOptions(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * « Ajouter un formateur »: a TMS user (user_id), or a trainer without
     * account (person: last name, first name, e-mail). The person of the CRM
     * behind him is created or reused by e-mail, never searched here.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TrainerStatus::class)],
            'user_id' => ['required_without:person', 'nullable', 'uuid'],
            'person' => ['nullable', 'array'],
            'person.last_name' => ['required_with:person', 'nullable', 'string', 'max:100'],
            'person.first_name' => ['nullable', 'string', 'max:100'],
            'person.email' => ['nullable', 'string', 'email', 'max:254'],
        ], [
            'status.required' => 'Choisissez « Salarié » ou « Indépendant ».',
            'status.enum' => 'Choisissez « Salarié » ou « Indépendant ».',
            'user_id.required_without' => 'Choisissez un utilisateur ou saisissez un formateur sans compte.',
            'user_id.uuid' => "Ce compte n'existe pas.",
            'person.array' => 'Indiquez le formateur.',
            'person.last_name.required_with' => 'Indiquez le nom du formateur.',
            'person.last_name.string' => 'Indiquez le nom du formateur.',
            'person.last_name.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'person.first_name.string' => 'Le prénom doit être un texte.',
            'person.first_name.max' => 'Le prénom ne doit pas dépasser 100 caractères.',
            'person.email.string' => 'Indiquez une adresse e-mail valide.',
            'person.email.email' => 'Indiquez une adresse e-mail valide.',
            'person.email.max' => "L'adresse e-mail ne doit pas dépasser 254 caractères.",
        ]);
        $status = TrainerStatus::from((string) $data['status']);

        if (isset($data['user_id'])) {
            $user = User::query()->find($data['user_id']);
            if ($user === null || ! $user->isActive()) {
                throw ValidationException::withMessages(['user_id' => "Ce compte n'existe pas."]);
            }
            $trainer = Trainers::declareUser($user, $status);
        } else {
            $person = $data['person'] ?? [];
            if (! is_string($person['last_name'] ?? null) || trim($person['last_name']) === '') {
                throw ValidationException::withMessages(['person.last_name' => 'Indiquez le nom du formateur.']);
            }
            $trainer = Trainers::declarePerson($person['last_name'], $person['first_name'] ?? null, $person['email'] ?? null, $status);
        }

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
                'das_ids' => $trainer->das()->pluck('das_id')->map(fn ($id) => (string) $id)->values()->all(),
                'user' => $account === null ? null : ['id' => $account->id, 'name' => $account->name, 'email' => $account->email, 'active' => $account->isActive()],
                'fiche_url' => isset($fiches->openable($viewer, 'person', [$trainer->person_id])[$trainer->person_id]) ? "/crm/people/{$trainer->person_id}" : null,
            ],
            'unavailabilities' => UnavailabilityInput::rows($trainer->unavailabilities()->orderByDesc('starts_at')->get()),
            'statusOptions' => TrainerStatus::options(),
            'dasOptions' => SessionListQuery::reference(ReferenceList::Das, $trainer->das()->pluck('das_id')->all()),
            // DAS he may attach or detach (null scope « Ses DAS »: those he follows only).
            'dasEditable' => Scopes::of($viewer, Permissions::TRAINERS_MANAGE) === AccessScope::Das ? UserDas::ids($viewer) : null,
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

    /**
     * « DAS » of the trainer: exactly the ticked active DAS of the organisme
     * (a DAS already attached stays even when deactivated).
     */
    public function updateDas(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $request->validate([
            'das_ids' => ['present', 'array', 'max:50'],
            'das_ids.*' => ['bail', 'string', 'uuid'],
        ], [
            'das_ids.present' => 'Choisissez les DAS du formateur.',
            'das_ids.array' => 'Choisissez les DAS du formateur.',
            'das_ids.max' => 'Trop de DAS sont choisis.',
            'das_ids.*.string' => "Ce DAS n'existe pas.",
            'das_ids.*.uuid' => "Ce DAS n'existe pas.",
        ]);
        $ids = array_values(array_unique(array_map('strval', $data['das_ids'])));
        $kept = $trainer->das()->pluck('das_id')->map(fn ($id) => (string) $id)->all();
        $valid = ReferenceItem::query()->where('list', ReferenceList::Das)->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $kept))->pluck('id')->map(fn ($id) => (string) $id)->all();
        $errors = [];
        foreach (array_values($data['das_ids']) as $i => $id) {
            if (! in_array($id, $valid, true)) {
                $errors["das_ids.$i"] = "Ce DAS n'existe pas ou n'est plus actif.";
            }
        }
        // « Ses DAS »: only the DAS he follows may be attached or detached.
        $viewer = $request->user();
        assert($viewer instanceof User);
        if (Scopes::of($viewer, Permissions::TRAINERS_MANAGE) === AccessScope::Das) {
            $followed = UserDas::ids($viewer);
            foreach (array_values($data['das_ids']) as $i => $id) {
                if (! in_array($id, $followed, true) && ! in_array($id, $kept, true)) {
                    $errors["das_ids.$i"] = self::NOT_FOLLOWED;
                }
            }
            if ($errors === [] && array_diff(array_diff($kept, $followed), $ids) !== []) {
                $errors['das_ids'] = self::NOT_FOLLOWED;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        Trainers::setDas($trainer, $ids);

        return back()->with('status', 'DAS du formateur enregistrés.');
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
}
