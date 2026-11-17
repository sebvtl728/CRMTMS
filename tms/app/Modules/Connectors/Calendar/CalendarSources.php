<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Calendar sources other modules add to the iCal feed of a user, without
 * Connectors knowing them (Sessions: the séances of the trainer linked to
 * the user and of the sessions he is responsible for). Each registers:
 *
 *  - $eventsFor(User, $from, $to): its events starting in [$from, $to), the
 *    module applying its own rights (never a personal datum it would not
 *    show the user);
 *  - $serves(User): whether the user has something there, which opens « Mon
 *    agenda » and the feed to him even without the CRM.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class CalendarSources
{
    /** @var array<string, array{events: Closure(User, CarbonImmutable, CarbonImmutable): list<CalendarEvent>, serves: Closure(User): bool}> */
    private array $sources = [];

    /**
     * @param  string  $key  unique name (module.source); a second registration replaces the first
     * @param  Closure(User, CarbonImmutable, CarbonImmutable): list<CalendarEvent>  $eventsFor
     * @param  Closure(User): bool  $serves
     */
    public static function register(string $key, Closure $eventsFor, Closure $serves): void
    {
        self::registry()->sources[$key] = ['events' => $eventsFor, 'serves' => $serves];
    }

    /**
     * The events of every source for $user, starting in [$from, $to).
     *
     * @return list<CalendarEvent>
     */
    public static function eventsFor(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = [];
        foreach (self::registry()->sources as $source) {
            array_push($events, ...($source['events'])($user, $from, $to));
        }

        return $events;
    }

    /**
     * A source has something for $user.
     */
    public static function serves(User $user): bool
    {
        foreach (self::registry()->sources as $source) {
            if (($source['serves'])($user)) {
                return true;
            }
        }

        return false;
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
