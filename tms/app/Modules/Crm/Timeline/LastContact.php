<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Keeps last_contact_at (list column, GDPR retention clock) in step with the
 * notes (and, from lot 1c, the actions done: registerSource). Written
 * without touching updated_at nor the journal: the note is the trace.
 *
 * record() only moves the date forward; recompute() sets it back to the
 * latest remaining contact (after notes were removed, e.g. an import undo).
 */
final class LastContact
{
    /** @var list<Closure(string, list<string>): ?CarbonInterface> */
    private array $sources = [];

    public static function record(string $subjectType, string $subjectId, CarbonInterface $at): void
    {
        if ($subjectType === (new Person)->getMorphClass()) {
            self::set('people', [$subjectId], $at);
            self::set('organisations', self::activeOrganisations($subjectId), $at);
        } elseif ($subjectType === (new Organisation)->getMorphClass()) {
            self::set('organisations', [$subjectId], $at);
        }
    }

    /**
     * Another source of contacts. $latest(subjectType, subjectIds) returns the
     * most recent contact of those fiches (all of the same type), or null.
     * Kept in the application container: a fresh application (each test)
     * starts with only what the providers register.
     *
     * @param  Closure(string, list<string>): ?CarbonInterface  $latest
     */
    public static function registerSource(Closure $latest): void
    {
        self::registry()->sources[] = $latest;
    }

    /**
     * Sets last_contact_at of the fiche to its latest remaining contact (null
     * when none). For an entreprise, the contacts of the people currently
     * linked to it count too, as record() propagated them; for a person, her
     * current entreprises are recomputed as well.
     */
    public static function recompute(string $subjectType, string $subjectId): void
    {
        $person = (new Person)->getMorphClass();
        $organisation = (new Organisation)->getMorphClass();

        if ($subjectType === $person) {
            DB::table('people')->where('id', $subjectId)->update(['last_contact_at' => self::literal(self::latest($person, [$subjectId]))]);
            foreach (self::activeOrganisations($subjectId) as $organisationId) {
                self::recompute($organisation, $organisationId);
            }
        } elseif ($subjectType === $organisation) {
            $people = DB::table('affiliations')->where('organisation_id', $subjectId)
                ->whereNull('ended_on')->whereNull('deleted_at')->pluck('person_id')->all();
            $latest = self::max(self::latest($organisation, [$subjectId]), self::latest($person, $people));
            DB::table('organisations')->where('id', $subjectId)->update(['last_contact_at' => self::literal($latest)]);
        }
    }

    /**
     * Most recent contact of the fiches (same type), all sources together.
     *
     * @param  list<string>  $ids
     */
    private static function latest(string $subjectType, array $ids): ?CarbonImmutable
    {
        if ($ids === []) {
            return null;
        }

        // One indexed query per subject type (notes_notable_type_notable_id_created_at_index).
        $notes = Note::query()->where('notable_type', $subjectType)->whereIn('notable_id', $ids)->max('created_at');
        $latest = is_string($notes) ? CarbonImmutable::parse($notes) : null;

        foreach (self::registry()->sources as $source) {
            $at = $source($subjectType, $ids);
            $latest = self::max($latest, $at === null ? null : CarbonImmutable::instance($at));
        }

        return $latest;
    }

    /**
     * Exact instant with offset and microseconds (a bound Carbon would be
     * formatted to the second, without zone).
     */
    private static function literal(?CarbonImmutable $at): ?string
    {
        return $at?->format('Y-m-d H:i:s.uP');
    }

    private static function max(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    /**
     * @return list<string>
     */
    private static function activeOrganisations(string $personId): array
    {
        return DB::table('affiliations')->where('person_id', $personId)
            ->whereNull('ended_on')->whereNull('deleted_at')->pluck('organisation_id')->all();
    }

    /**
     * @param  list<string>  $ids
     */
    private static function set(string $table, array $ids, CarbonInterface $at): void
    {
        if ($ids === []) {
            return;
        }

        DB::table($table)->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('last_contact_at')->orWhere('last_contact_at', '<', $at))
            ->update(['last_contact_at' => $at]);
    }

    private static function registry(): self
    {
        $app = app();
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self);
        }

        return $app->make(self::class);
    }
}
