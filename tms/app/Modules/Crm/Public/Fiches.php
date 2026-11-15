<?php

namespace App\Modules\Crm\Public;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Str;

/**
 * Public read façade of the fiches for the other modules: the minimal
 * identity of an entreprise or a person of the organisme (fiches out of the
 * trash), whatever the CRM visibility of the user. The caller decides who
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
     * @return array{id: string, label: string, birth_date: ?string}|null
     */
    public function person(string $id): ?array
    {
        $person = Str::isUuid($id) ? Person::query()->find($id) : null;

        return $person === null ? null : ['id' => $person->id, 'label' => $person->fullName(), 'birth_date' => $person->birth_date?->toDateString()];
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
