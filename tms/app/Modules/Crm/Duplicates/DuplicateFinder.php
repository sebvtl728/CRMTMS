<?php

namespace App\Modules\Crm\Duplicates;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Fiches that may already describe what is being entered. Rules (best one
 * kept per fiche): same SIRET (1.0), same e-mail ignoring case (0.95), same
 * normalised phone (0.9), name similarity >= 0.6 together with the same
 * postal code or city (score = similarity). At most 5, best first.
 *
 * Every query runs under RLS (current organisme only) on non-trashed fiches,
 * through an index (see migration 2026_11_17_000001) and with a LIMIT.
 */
class DuplicateFinder
{
    public const MAX = 5;

    public const NAME_THRESHOLD = 0.6;

    private const ORG_NAME = 'public.immutable_unaccent(lower(organisations.name))';

    private const PERSON_NAME = "public.immutable_unaccent(lower(people.last_name || ' ' || coalesce(people.first_name, '')))";

    /**
     * @param  array<string, mixed>  $attributes  name, siret, email, phone, postal_code, city
     * @return Collection<int, DuplicateCandidate>
     */
    public function forOrganisation(array $attributes, ?string $ignoreId = null): Collection
    {
        $base = fn (): Builder => Organisation::query()->when($ignoreId, fn (Builder $q, string $id) => $q->whereKeyNot($id));
        $label = fn (Organisation $o): string => $o->name.($o->city ? ' ('.$o->city.')' : '');
        $found = collect();

        $siret = self::text($attributes['siret'] ?? null);
        if ($siret !== null) {
            $siret = preg_replace('/[\s.]/u', '', $siret) ?? $siret;
            $found = $found->concat($this->candidates($base()->where('siret', $siret), $label, 'siret', 1.0));
        }

        $email = self::email($attributes['email'] ?? null);
        if ($email !== null) {
            $found = $found->concat($this->candidates($base()->whereNotNull('email')->whereRaw('lower(organisations.email) = ?', [$email]), $label, 'email', 0.95));
        }

        // Entreprise phones are stored as typed: compared on their digits.
        $phones = self::phoneDigits($attributes['phone'] ?? null);
        if ($phones !== []) {
            $placeholders = implode(',', array_fill(0, count($phones), '?'));
            $found = $found->concat($this->candidates(
                $base()->whereNotNull('phone')->whereRaw("regexp_replace(organisations.phone, '\\D', '', 'g') in ({$placeholders})", $phones),
                $label, 'phone', 0.9));
        }

        $name = self::text($attributes['name'] ?? null);
        if ($name !== null) {
            $found = $found->concat($this->byName($base(), self::ORG_NAME, $name, $attributes, 'organisations', $label));
        }

        return $this->best($found);
    }

    /**
     * @param  array<string, mixed>  $attributes  last_name, first_name, email, email_secondary, phone, mobile, postal_code, city
     * @return Collection<int, DuplicateCandidate>
     */
    public function forPerson(array $attributes, ?string $ignoreId = null): Collection
    {
        $base = fn (): Builder => Person::query()->when($ignoreId, fn (Builder $q, string $id) => $q->whereKeyNot($id));
        $label = fn (Person $p): string => $p->fullName().($p->city ? ' ('.$p->city.')' : '');
        $found = collect();

        $emails = array_values(array_unique(array_filter([self::email($attributes['email'] ?? null), self::email($attributes['email_secondary'] ?? null)])));
        foreach ($emails as $email) {
            $found = $found->concat($this->candidates($base()->where(fn (Builder $q) => $q
                ->where(fn (Builder $e) => $e->whereNotNull('email')->whereRaw('lower(people.email) = ?', [$email]))
                ->orWhere(fn (Builder $e) => $e->whereNotNull('email_secondary')->whereRaw('lower(people.email_secondary) = ?', [$email]))),
                $label, 'email', 0.95));
        }

        // People phones are stored normalised (E.164 for French numbers).
        $phones = [];
        foreach (['phone', 'mobile'] as $key) {
            $value = self::text($attributes[$key] ?? null);
            $normalised = $value === null ? null : PhoneNumber::normalise($value);
            if ($normalised !== null && strlen(preg_replace('/\D/', '', $normalised) ?? '') >= 8) {
                $phones[] = $normalised;
            }
        }
        $phones = array_values(array_unique($phones));
        if ($phones !== []) {
            $found = $found->concat($this->candidates($base()->where(fn (Builder $q) => $q->whereIn('phone', $phones)->orWhereIn('mobile', $phones)), $label, 'phone', 0.9));
        }

        $lastName = self::text($attributes['last_name'] ?? null);
        if ($lastName !== null) {
            $name = trim($lastName.' '.(self::text($attributes['first_name'] ?? null) ?? ''));
            $found = $found->concat($this->byName($base(), self::PERSON_NAME, $name, $attributes, 'people', $label));
        }

        return $this->best($found);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): string  $label
     * @return Collection<int, DuplicateCandidate>
     */
    private function candidates(Builder $query, callable $label, string $reason, float $score): Collection
    {
        return $query->limit(self::MAX)->get()
            ->map(fn (Model $m) => new DuplicateCandidate((string) $m->getKey(), $label($m), $reason, $score))
            ->values();
    }

    /**
     * Similar name (trigram index through the % operator, then the 0.6
     * threshold) AND same postal code or same city (accent/case-insensitive).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes
     * @param  callable(TModel): string  $label
     * @return Collection<int, DuplicateCandidate>
     */
    private function byName(Builder $query, string $expression, string $name, array $attributes, string $table, callable $label): Collection
    {
        $postalCode = self::text($attributes['postal_code'] ?? null);
        $city = self::text($attributes['city'] ?? null);

        if ($postalCode === null && $city === null) {
            return collect();
        }

        $rows = $query
            ->select("{$table}.*")
            ->selectRaw("similarity({$expression}, public.immutable_unaccent(lower(?))) as name_score", [$name])
            ->whereRaw("{$expression} % public.immutable_unaccent(lower(?))", [$name])
            ->whereRaw("similarity({$expression}, public.immutable_unaccent(lower(?))) >= ?", [$name, self::NAME_THRESHOLD])
            ->where(fn (Builder $q) => $q
                ->when($postalCode, fn (Builder $p, string $v) => $p->orWhereRaw("trim({$table}.postal_code) = ?", [$v]))
                ->when($city, fn (Builder $c, string $v) => $c->orWhereRaw("public.immutable_unaccent(lower(trim({$table}.city))) = public.immutable_unaccent(lower(?))", [$v])))
            ->orderByDesc('name_score')
            ->orderBy("{$table}.id")
            ->limit(self::MAX)
            ->get();

        return $rows->map(fn (Model $m) => new DuplicateCandidate((string) $m->getKey(), $label($m), 'name', round((float) $m->getAttribute('name_score'), 4)))->values();
    }

    /**
     * One entry per fiche (its best reason), best first, at most MAX.
     *
     * @param  Collection<int, DuplicateCandidate>  $found
     * @return Collection<int, DuplicateCandidate>
     */
    private function best(Collection $found): Collection
    {
        return $found
            ->sortByDesc(fn (DuplicateCandidate $c) => $c->score)
            ->unique(fn (DuplicateCandidate $c) => $c->id)
            ->take(self::MAX)
            ->values();
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function email(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : mb_strtolower($value);
    }

    /**
     * Digit forms under which a phone may be stored as typed:
     * +33472001122 -> 33472001122, 0472001122, 0033472001122.
     *
     * @return list<string>
     */
    private static function phoneDigits(mixed $value): array
    {
        $value = self::text($value);
        if ($value === null) {
            return [];
        }

        $digits = preg_replace('/\D/', '', PhoneNumber::normalise($value) ?? $value) ?? '';
        if (strlen($digits) < 8) {
            return [];
        }

        $forms = [$digits, '00'.$digits];
        if (str_starts_with($digits, '33') && strlen($digits) === 11) {
            $forms[] = '0'.substr($digits, 2);
        }

        return array_values(array_unique($forms));
    }
}
