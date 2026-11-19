<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Duplicates\DuplicateFinder;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PersonRequest;
use App\Modules\Crm\Support\DasLinks;
use App\Modules\Crm\Support\ReferenceRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Public façade of the people as trainees (lot 2a, enrolments).
 *
 * The search covers every person of the organisme (out of the trash, not
 * anonymised), whatever the CRM visibility of the user, so that a user
 * limited to his DAS finds the existing person instead of creating a
 * duplicate (spec § 9). It returns the minimal identity only: name, first
 * name, birth date, current entreprise. The endpoint exposing it is
 * restricted to sessions.sessions.update by the caller.
 */
final class Trainees
{
    private const MAX_LIMIT = 50;

    /** Fields a trainee is created with. */
    /** Fields of the training profile updateProfile() accepts. */
    private const PROFILE_FIELDS = ['education_level_id', 'professional_situation_id', 'birth_date'];

    private const FIELDS = ['civility', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'postal_code', 'city', 'birth_date', 'is_individual'];

    public function __construct(private readonly DuplicateFinder $duplicates) {}

    /**
     * People whose name or e-mail contains every word of $q (accent- and
     * case-insensitive); the active employees of $organisationId first, then
     * by name.
     *
     * @return list<array{id: string, last_name: string, first_name: ?string, birth_date: ?string, organisation_label: ?string}>
     */
    public function search(string $q, ?string $organisationId = null, int $limit = 15): array
    {
        $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }

        $query = Person::query()->whereNull('people.anonymised_at');
        foreach ($words as $word) {
            $query->whereRaw('people.search_vector like public.immutable_unaccent(lower(?))', ['%'.addcslashes($word, '\\%_').'%']);
        }
        if ($organisationId !== null && Str::isUuid($organisationId)) {
            $query->orderByRaw('case when exists (select 1 from affiliations a where a.person_id = people.id and a.organisation_id = ? and a.ended_on is null and a.deleted_at is null) then 0 else 1 end', [$organisationId]);
        }

        return self::rows($query->orderBy('people.last_name')->orderBy('people.first_name')->orderBy('people.id')->limit(max(1, min($limit, self::MAX_LIMIT))));
    }

    /**
     * Ids of the people (trash included, anonymised ones excluded) whose name
     * or e-mail contains every word of $q, as a subquery without limit: for a
     * module filtering its own records by person (whereIn).
     */
    public function matchingIds(string $q): \Illuminate\Database\Query\Builder
    {
        $query = Person::withTrashed()->whereNull('people.anonymised_at')->select('people.id');
        $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            $query->whereRaw('false');
        }
        foreach ($words as $word) {
            $query->whereRaw('people.search_vector like public.immutable_unaccent(lower(?))', ['%'.addcslashes($word, '\\%_').'%']);
        }

        return $query->toBase();
    }

    /**
     * Creates an ordinary person of the CRM (journaled), optionally employed
     * by $data['organisation_id'] and given the DAS $data['das_ids'] (active
     * DAS of the organisme: those of the training the trainee is enrolled
     * on, so that a user limited to his DAS keeps seeing her). Throws DuplicateTrainee when similar
     * people exist (same rules as the person form), unless
     * $data['confirm_duplicate'] is true; ValidationException (French
     * messages) on invalid data.
     *
     * @param  array<string, mixed>  $data
     * @return string the new person's id
     */
    public function create(array $data): string
    {
        $input = [
            ...PersonRequest::normalise(array_intersect_key($data, array_flip(self::FIELDS))),
            ...array_intersect_key($data, array_flip(['is_individual', 'organisation_id', 'das_ids'])),
        ];
        $rules = array_intersect_key(PersonRequest::fieldRules(), array_flip(self::FIELDS));
        $rules['organisation_id'] = ['nullable', 'uuid', Rule::exists(Organisation::class, 'id')->whereNull('deleted_at')];
        $rules['das_ids'] = ['nullable', 'array', 'max:20'];
        $rules['das_ids.*'] = ['distinct', ...ReferenceRules::item(ReferenceList::Das)];

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $rules, PersonRequest::validationMessages())->validate();
        $organisationId = $validated['organisation_id'] ?? null;
        /** @var list<string> $dasIds */
        $dasIds = array_values($validated['das_ids'] ?? []);
        unset($validated['organisation_id'], $validated['das_ids']);

        if (($data['confirm_duplicate'] ?? false) !== true) {
            $found = $this->duplicates->forPerson($validated);
            if ($found->isNotEmpty()) {
                throw new DuplicateTrainee(self::rows(Person::query()->whereNull('people.anonymised_at')->whereKey($found->pluck('id')->all())->orderBy('people.last_name')->orderBy('people.id')));
            }
        }

        return DB::transaction(function () use ($validated, $organisationId, $dasIds): string {
            $person = Person::query()->create([...$validated, 'country' => 'FR', 'status' => OrganisationStatus::Prospect->value]);
            if (is_string($organisationId)) {
                Affiliation::query()->create(['person_id' => $person->id, 'organisation_id' => $organisationId]);
            }
            app(DasLinks::class)->add($person, $dasIds);

            return $person->id;
        });
    }

    /**
     * Contact details and training profile of a person out of anonymisation
     * (trashed included: a record that points to her keeps showing her);
     * null when unknown or anonymised.
     *
     * @return array{id: string, label: string, email: ?string, phone: ?string, mobile: ?string, birth_date: ?string, education_level_id: ?string, professional_situation_id: ?string, trashed: bool}|null
     */
    public function profile(string $personId): ?array
    {
        $person = Str::isUuid($personId) ? Person::withTrashed()->whereNull('people.anonymised_at')->find($personId) : null;
        if ($person === null) {
            return null;
        }

        return [
            'id' => $person->id,
            'label' => $person->fullName(),
            'email' => $person->email,
            'phone' => $person->phone,
            'mobile' => $person->mobile,
            'birth_date' => $person->birth_date?->toDateString(),
            'education_level_id' => $person->education_level_id,
            'professional_situation_id' => $person->professional_situation_id,
            'trashed' => $person->trashed(),
        ];
    }

    /**
     * Updates the training profile of a person: only the keys present in
     * $changes (education_level_id, professional_situation_id, birth_date as
     * Y-m-d) are changed, a present null clears the value, an absent key is
     * kept. Reference values must be active ones of their list, or the one
     * already stored. Journaled like any change of the fiche (the birth date
     * as a personal value). InvalidArgumentException on an unknown key;
     * ValidationException (French messages) on invalid data; a missing or
     * anonymised person is a ModelNotFoundException.
     *
     * @param  array<string, string|null>  $changes
     */
    public function updateProfile(string $personId, array $changes): void
    {
        $unknown = array_diff(array_keys($changes), self::PROFILE_FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Champ de profil inconnu : '.implode(', ', $unknown));
        }

        $person = Str::isUuid($personId) ? Person::query()->whereNull('people.anonymised_at')->find($personId) : null;
        if ($person === null) {
            throw (new ModelNotFoundException)->setModel(Person::class, [$personId]);
        }

        $rules = array_intersect_key([
            'education_level_id' => ['nullable', ...ReferenceRules::item(ReferenceList::EducationLevel, $person->education_level_id)],
            'professional_situation_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ProfessionalSituation, $person->professional_situation_id)],
            'birth_date' => PersonRequest::fieldRules()['birth_date'],
        ], $changes);

        /** @var array<string, string|null> $validated */
        $validated = Validator::make(
            $changes,
            $rules,
            PersonRequest::validationMessages(),
            ['education_level_id' => 'niveau de diplôme', 'professional_situation_id' => 'situation professionnelle'],
        )->validate();

        DB::transaction(fn () => $person->update($validated));
    }

    /**
     * Minimal identity of the people of $query; the current entreprise is
     * that of the latest active affiliation.
     *
     * @param  Builder<Person>  $query
     * @return list<array{id: string, last_name: string, first_name: ?string, birth_date: ?string, organisation_label: ?string}>
     */
    private static function rows(Builder $query): array
    {
        $current = Organisation::query()->select('organisations.name')
            ->join('affiliations', 'affiliations.organisation_id', '=', 'organisations.id')
            ->whereColumn('affiliations.person_id', 'people.id')
            ->whereNull('affiliations.ended_on')->whereNull('affiliations.deleted_at')
            ->orderByRaw('affiliations.started_on desc nulls last')->orderByDesc('affiliations.created_at')
            ->limit(1);

        return $query->select(['people.id', 'people.last_name', 'people.first_name', 'people.birth_date'])
            ->addSelect(['organisation_label' => $current])
            ->get()
            ->map(fn (Person $p): array => [
                'id' => $p->id,
                'last_name' => $p->last_name,
                'first_name' => $p->first_name,
                'birth_date' => $p->birth_date?->toDateString(),
                'organisation_label' => $p->getAttribute('organisation_label'),
            ])->values()->all();
    }
}
