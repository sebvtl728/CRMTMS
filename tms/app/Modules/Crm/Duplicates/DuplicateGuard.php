<?php

namespace App\Modules\Crm\Duplicates;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Duplicate check of the create / edit forms. Without confirm_duplicate=1,
 * candidates stop the save: a JSON client gets 409 {message, duplicates};
 * an Inertia form gets a validation-style error « duplicates » and the
 * candidates as a flashed page prop (an Inertia visit cannot read a 409).
 * Only candidates the user may see are reported.
 */
class DuplicateGuard
{
    public const MESSAGE = 'Des fiches similaires existent déjà.';

    public const SESSION_KEY = 'duplicates';

    private const ORGANISATION_KEYS = ['name', 'siret', 'email', 'phone', 'postal_code', 'city'];

    private const PERSON_KEYS = ['last_name', 'first_name', 'email', 'email_secondary', 'phone', 'mobile', 'postal_code', 'city'];

    public function __construct(private readonly DuplicateFinder $finder) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function organisation(Request $request, array $validated, ?Organisation $current, string $formUrl): ?Response
    {
        $attributes = $this->attributes($validated, $current, self::ORGANISATION_KEYS);
        if ($attributes === null || $request->boolean('confirm_duplicate')) {
            return null;
        }

        $found = $this->finder->forOrganisation($attributes, $current?->id);
        $visible = $this->visibleIds($found, fn (User $u) => CrmVisibility::organisations(Organisation::query(), $u)->select('organisations.id'), $request);

        return $this->respond($request, $found->filter(fn (DuplicateCandidate $c) => in_array($c->id, $visible, true))->values(), $formUrl);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function person(Request $request, array $validated, ?Person $current, string $formUrl): ?Response
    {
        $attributes = $this->attributes($validated, $current, self::PERSON_KEYS);
        if ($attributes === null || $request->boolean('confirm_duplicate')) {
            return null;
        }

        $found = $this->finder->forPerson($attributes, $current?->id);
        $visible = $this->visibleIds($found, fn (User $u) => CrmVisibility::people(Person::query(), $u)->select('people.id'), $request);

        return $this->respond($request, $found->filter(fn (DuplicateCandidate $c) => in_array($c->id, $visible, true))->values(), $formUrl);
    }

    /**
     * Fiches resembling an existing one (« Fiches similaires » panel of the
     * fiche, offered to those who may merge), visible to $user only.
     *
     * @return list<array<string, mixed>>
     */
    public function similarTo(Organisation|Person $fiche, User $user): array
    {
        if ($fiche->trashed()) {
            return [];
        }

        if ($fiche instanceof Organisation) {
            $found = $this->finder->forOrganisation($fiche->only(self::ORGANISATION_KEYS), $fiche->id);
            $visible = CrmVisibility::organisations(Organisation::query(), $user)->select('organisations.id');
        } else {
            $found = $this->finder->forPerson($fiche->only(self::PERSON_KEYS), $fiche->id);
            $visible = CrmVisibility::people(Person::query(), $user)->select('people.id');
        }

        if ($found->isEmpty()) {
            return [];
        }
        $ids = $visible->whereKey($found->pluck('id')->all())->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $found->filter(fn (DuplicateCandidate $c) => in_array($c->id, $ids, true))
            ->map(fn (DuplicateCandidate $c) => $c->toArray())->values()->all();
    }

    /**
     * Candidates flashed by the last refused save (page prop of the form).
     *
     * @return list<array<string, mixed>>
     */
    public static function flashed(Request $request): array
    {
        $value = $request->session()->get(self::SESSION_KEY);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Attributes to look for. On an edit, only what changed is looked for
     * (the user may already have chosen to keep both fiches): a changed
     * e-mail / phone / SIRET alone, and the name rule as a whole (name,
     * postal code, city) when one of its parts changed. Null when nothing
     * identifying changed.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private function attributes(array $validated, Organisation|Person|null $current, array $keys): ?array
    {
        if ($current === null) {
            return array_intersect_key($validated, array_flip($keys));
        }

        $nameGroup = array_values(array_intersect($keys, ['name', 'last_name', 'first_name', 'postal_code', 'city']));
        $merged = [];
        $changed = [];
        foreach ($keys as $key) {
            $stored = $current->getAttribute($key);
            $merged[$key] = array_key_exists($key, $validated) ? $validated[$key] : $stored;
            if (array_key_exists($key, $validated) && mb_strtolower(trim((string) $validated[$key])) !== mb_strtolower(trim((string) $stored))) {
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return null;
        }
        if (array_intersect($changed, $nameGroup) !== []) {
            $changed = array_unique([...$changed, ...$nameGroup]);
        }

        return array_intersect_key($merged, array_flip($changed));
    }

    /**
     * @param  Collection<int, DuplicateCandidate>  $found
     * @param  callable(User): Builder<covariant \Illuminate\Database\Eloquent\Model>  $visible
     * @return list<string>
     */
    private function visibleIds(Collection $found, callable $visible, Request $request): array
    {
        $user = $request->user();
        if ($found->isEmpty() || ! $user instanceof User) {
            return [];
        }

        return $visible($user)->whereKey($found->pluck('id')->all())->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @param  Collection<int, DuplicateCandidate>  $candidates
     */
    private function respond(Request $request, Collection $candidates, string $formUrl): ?Response
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $list = $candidates->map(fn (DuplicateCandidate $c) => $c->toArray())->all();

        if ($request->header('X-Inertia') === null && $request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE, 'duplicates' => $list], 409);
        }

        return redirect()->to($formUrl)
            ->withErrors(['duplicates' => self::MESSAGE])
            ->with(self::SESSION_KEY, $list);
    }
}
