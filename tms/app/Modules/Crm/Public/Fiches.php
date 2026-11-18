<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Str;

/**
 * Public façade of the fiches for the other modules (and personForTrainer(),
 * its one writer: the person behind a trainer). Reads give the minimal
 * identity of an entreprise or a person of the organisme (fiches out of the
 * trash, people not anonymised), whatever the CRM visibility of the user. The caller decides who
 * may see it (a dossier's contractant, a session's entreprise...).
 */
final class Fiches
{
    /**
     * @return array{id: string, label: string}|null
     */
    public function organisation(string $id): ?array
    {
        $organisation = Str::isUuid($id) ? Organisation::query()->find($id) : null;

        return $organisation === null ? null : ['id' => $organisation->id, 'label' => $organisation->name];
    }

    /**
     * Names of these entreprises keyed by id, trashed ones included: a record
     * that already points to one keeps showing it. Unknown ids are left out.
     *
     * @param  list<string>  $ids
     * @return array<string, array{label: string, trashed: bool}>
     */
    public function organisationLabels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id) => Str::isUuid($id))));
        if ($ids === []) {
            return [];
        }

        return Organisation::withTrashed()->whereKey($ids)->get(['id', 'name', 'deleted_at'])
            ->mapWithKeys(fn (Organisation $o) => [$o->id => ['label' => $o->name, 'trashed' => $o->trashed()]])->all();
    }

    /**
     * @return array{id: string, label: string, birth_date: ?string}|null
     */
    public function person(string $id): ?array
    {
        $person = Str::isUuid($id) ? Person::query()->whereNull('people.anonymised_at')->find($id) : null;

        return $person === null ? null : ['id' => $person->id, 'label' => $person->fullName(), 'birth_date' => $person->birth_date?->toDateString()];
    }

    /**
     * A particulier (person flagged « Particulier », out of the trash, not
     * anonymised): the contractant of a dossier when it is not an entreprise.
     *
     * @return array{id: string, label: string}|null
     */
    public function individual(string $id): ?array
    {
        $person = Str::isUuid($id) ? Person::query()->whereNull('people.anonymised_at')->where('people.is_individual', true)->find($id) : null;

        return $person === null ? null : ['id' => $person->id, 'label' => $person->fullName()];
    }

    /**
     * Who an invoice is addressed to, as it is copied onto the invoice when
     * it is validated (the invoicing): name, postal address, SIRET and SIREN
     * of an entreprise, its e-mail as the billing e-mail. A trashed fiche
     * too (its dossier still bills it); null when unknown. The CRM keeps no
     * intra-community VAT number: vat_number is always null for now.
     *
     * @param  'organisation'|'person'  $type
     * @return array{name: string, address_line1: ?string, address_line2: ?string, postal_code: ?string, city: ?string, country: ?string, siret: ?string, siren: ?string, vat_number: ?string, email: ?string}|null
     */
    public function billingIdentity(string $type, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $fiche = $type === 'organisation' ? Organisation::withTrashed()->find($id) : Person::withTrashed()->find($id);
        if ($fiche === null) {
            return null;
        }
        $siret = $fiche instanceof Organisation && $fiche->siret !== null && $fiche->siret !== '' ? (string) $fiche->siret : null;

        return [
            'name' => $fiche instanceof Organisation ? $fiche->name : $fiche->fullName(),
            'address_line1' => $fiche->address_line1,
            'address_line2' => $fiche->address_line2,
            'postal_code' => $fiche->postal_code,
            'city' => $fiche->city,
            'country' => $fiche->country,
            'siret' => $siret,
            'siren' => $siret === null ? null : substr($siret, 0, 9),
            'vat_number' => null,
            'email' => $fiche instanceof Person && $fiche->anonymised_at !== null ? null : $fiche->email,
        ];
    }

    /**
     * Names of these people keyed by id, trashed ones included (anonymised:
     * their anonymised name). Unknown ids are left out. The last and first
     * names apart too (exports).
     *
     * @param  list<string>  $ids
     * @return array<string, array{label: string, trashed: bool, last_name: string, first_name: ?string}>
     */
    public function personLabels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id) => Str::isUuid($id))));
        if ($ids === []) {
            return [];
        }

        return Person::withTrashed()->whereKey($ids)->get()
            ->mapWithKeys(fn (Person $p) => [$p->id => ['label' => $p->fullName(), 'trashed' => $p->trashed(), 'last_name' => $p->last_name, 'first_name' => $p->first_name]])->all();
    }

    /**
     * Primary e-mail of these people keyed by id (null when none), trashed
     * ones included, anonymised ones without e-mail. Unknown ids are left out.
     *
     * @param  list<string>  $ids
     * @return array<string, string|null>
     */
    public function personEmails(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id) => Str::isUuid($id))));
        if ($ids === []) {
            return [];
        }

        return Person::withTrashed()->whereKey($ids)->get(['id', 'email', 'anonymised_at'])
            ->mapWithKeys(fn (Person $p) => [$p->id => $p->anonymised_at === null ? $p->email : null])->all();
    }

    /**
     * Ids of the people of the organisme (out of the trash, not anonymised)
     * whose primary e-mail is $email, case-insensitive.
     *
     * @return list<string>
     */
    public function peopleWithEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        return Person::query()->whereNull('people.anonymised_at')->whereRaw('lower(people.email) = ?', [$email])
            ->orderBy('people.id')->pluck('people.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * The person behind a trainer of the Sessions module. Reused only when
     * exactly one person of the organisme (out of the trash, not anonymised)
     * has the primary e-mail $email AND the same last name (accents and case
     * aside); else a trashed one with both (exactly one, never merged nor
     * anonymised) is restored; otherwise a new person is created. A new one
     * is no prospect (status « Inactif »: no commercial relation), has no
     * creator nor responsible, so it never lands in anybody's « Ses fiches ».
     *
     * @return string the person's id
     */
    public function personForTrainer(string $lastName, ?string $firstName, ?string $email): string
    {
        $email = $email === null || trim($email) === '' ? null : mb_strtolower(trim($email));
        $name = self::comparable($lastName);
        if ($email !== null) {
            $live = Person::query()->whereNull('people.anonymised_at')->whereRaw('lower(people.email) = ?', [$email])->get(['id', 'last_name']);
            if ($live->count() === 1 && self::comparable((string) $live->first()?->last_name) === $name) {
                return (string) $live->firstOrFail()->id;
            }
            if ($live->isEmpty()) {
                $trashed = Person::onlyTrashed()->whereNull('people.anonymised_at')->whereNull('people.merged_into_id')
                    ->whereRaw('lower(people.email) = ?', [$email])->get()
                    ->filter(fn (Person $p) => self::comparable($p->last_name) === $name);
                if ($trashed->count() === 1) {
                    $person = $trashed->firstOrFail();
                    $person->restore();

                    return (string) $person->id;
                }
            }
        }
        $firstName = $firstName === null || trim($firstName) === '' ? null : trim($firstName);

        $id = (string) Person::query()->create([
            'last_name' => trim($lastName), 'first_name' => $firstName, 'email' => $email,
            'country' => 'FR', 'status' => OrganisationStatus::Inactif->value,
        ])->getKey();
        // Nobody's fiche: no creator (the creating hook sets the current user).
        Person::query()->whereKey($id)->update(['created_by' => null]);

        return $id;
    }

    private static function comparable(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }

    /**
     * Ids among $ids of the fiches (type organisation or person) that $user
     * can open in the CRM: crm.fiches.view and its scope, a trashed fiche
     * only with crm.fiches.delete, never a merged one. The other modules
     * show a link to the fiche for these, plain text otherwise.
     *
     * @param  'organisation'|'person'  $type
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function openable(User $user, string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id) => Str::isUuid($id))));
        if ($ids === [] || ! $user->can(Permissions::FICHES_VIEW)) {
            return [];
        }
        $query = $type === 'organisation'
            ? CrmVisibility::organisations(Organisation::withTrashed(), $user)
            : CrmVisibility::people(Person::withTrashed(), $user);
        $model = $query->getModel();
        $query->whereKey($ids)->whereNull($model->qualifyColumn('merged_into_id'));
        if (! $user->can(Permissions::FICHES_DELETE)) {
            $query->whereNull($model->qualifyColumn('deleted_at'));
        }

        return array_fill_keys($query->pluck($model->qualifyColumn('id'))->map(fn ($id) => (string) $id)->all(), true);
    }

    /**
     * Entreprises of the whole organisme whose name, city or SIRET matches every word of $q
     * (accent- and case-insensitive), by name.
     *
     * @return list<array{id: string, label: string, siret: ?string}>
     */
    public function searchOrganisations(string $q, int $limit = 10): array
    {
        if (trim($q) === '') {
            return [];
        }

        return Organisation::query()->search($q)
            ->orderBy('name')->orderBy('id')
            ->limit(max(1, min($limit, 50)))
            ->get(['id', 'name', 'siret'])
            ->map(fn (Organisation $o): array => ['id' => $o->id, 'label' => $o->name, 'siret' => $o->siret])
            ->values()->all();
    }
}
