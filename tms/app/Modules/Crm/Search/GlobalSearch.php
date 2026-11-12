<?php

namespace App\Modules\Crm\Search;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Ctrl+K search: entreprises and people matching a term, among the
 * fiches the user may see. Words are matched on the accent-insensitive
 * search_vector (trigram index); a SIRET, an e-mail or a phone number typed
 * in full is matched exactly, whatever its spacing. Trashed and merged
 * fiches never appear.
 */
class GlobalSearch
{
    public const MIN_LENGTH = 2;

    /**
     * @return array{organisations: list<array{id: string, name: string, subtitle: ?string, url: string}>, people: list<array{id: string, name: string, subtitle: ?string, url: string}>}
     */
    public function query(string $term, User $user, int $limit = 10): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['organisations' => [], 'people' => []];
        }

        return [
            'organisations' => $this->organisations($term, $user, $limit),
            'people' => $this->people($term, $user, $limit),
        ];
    }

    /**
     * @return list<array{id: string, name: string, subtitle: ?string, url: string}>
     */
    private function organisations(string $term, User $user, int $limit): array
    {
        $digits = preg_replace('/\D/', '', $term) ?? '';
        $onlyDigits = preg_match('/^[\d\s.\-+()]+$/', $term) === 1;
        $prefix = addcslashes(mb_strtolower($term), '\\%_').'%';

        $query = CrmVisibility::organisations(Organisation::query(), $user)
            ->where(function (Builder $q) use ($term, $digits, $onlyDigits): void {
                $q->where(fn (Builder $w) => $w->search($term));

                if ($onlyDigits && strlen($digits) === 14) {
                    $q->orWhere('organisations.siret', $digits);
                }
                if (str_contains($term, '@')) {
                    $q->orWhereRaw('lower(organisations.email) = ?', [mb_strtolower($term)]);
                }
                if ($onlyDigits && strlen($digits) >= 6) {
                    // Typed « +33 4 50… » or « 04 50… »: the stored phone is as typed.
                    $local = str_starts_with($term, '+') && str_starts_with($digits, '33') ? '0'.substr($digits, 2) : $digits;
                    $q->orWhereRaw("regexp_replace(organisations.phone, '\\D', '', 'g') in (?, ?)", [$local, '33'.substr($local, 1)]);
                }
            })
            ->orderByRaw('case when public.immutable_unaccent(lower(organisations.name)) like ? then 0 else 1 end', [$prefix])
            ->orderBy('name')->orderBy('id')
            ->limit($limit);

        return $query->get()->map(fn (Organisation $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'subtitle' => collect([$o->city, $o->siret])->filter()->implode(' · ') ?: null,
            'url' => route('crm.organisations.show', $o->id, false),
        ])->all();
    }

    /**
     * @return list<array{id: string, name: string, subtitle: ?string, url: string}>
     */
    private function people(string $term, User $user, int $limit): array
    {
        $query = CrmVisibility::people(Person::query(), $user)
            ->with('activeAffiliations.organisation')
            ->search($term)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->limit($limit);

        return $query->get()->map(function (Person $p) {
            $organisations = $p->activeAffiliations->map(fn ($a) => $a->organisation?->name)->filter()->unique()->implode(', ');

            return [
                'id' => $p->id,
                'name' => $p->fullName(),
                'subtitle' => $organisations !== '' ? $organisations : (collect([$p->is_individual ? 'Particulier' : null, $p->city])->filter()->implode(' · ') ?: null),
                'url' => route('crm.people.show', $p->id, false),
            ];
        })->all();
    }
}
