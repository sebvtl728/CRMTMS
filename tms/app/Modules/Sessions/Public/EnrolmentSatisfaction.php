<?php

namespace App\Modules\Sessions\Public;

use Closure;

/**
 * The satisfaction shown on the trainings followed of a person (« Terminée
 * le … · Satisfaction : 4,5/5 »), given by another module (Questionnaires)
 * without Sessions knowing it: each registers a closure receiving the
 * enrolment ids and returning a label per enrolment id.
 */
final class EnrolmentSatisfaction
{
    /** @var list<Closure(list<string>): array<string, string>> */
    private array $sources = [];

    /**
     * @param  Closure(list<string>): array<string, string>  $source
     */
    public static function register(Closure $source): void
    {
        self::registry()->sources[] = $source;
    }

    /**
     * @param  list<string>  $enrolmentIds
     * @return array<string, string>
     */
    public static function for(array $enrolmentIds): array
    {
        if ($enrolmentIds === []) {
            return [];
        }
        $labels = [];
        foreach (self::registry()->sources as $source) {
            $labels = [...$labels, ...$source($enrolmentIds)];
        }

        return $labels;
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
