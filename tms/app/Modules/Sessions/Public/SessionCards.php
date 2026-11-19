<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Core\Users\User;
use Closure;

/**
 * Cards other modules add to the « Synthèse » of the sessions (the page and
 * the kanban: SessionChecklist), after its own: each registers a closure
 * receiving the ids of the (not cancelled) sessions and the viewer, and
 * returns, per session id, its cards (key, title, value, state done|todo|
 * alert|na, text, step, anchor), in grouped queries.
 */
final class SessionCards
{
    /** @var array<string, Closure(list<string>, User): array<string, list<array{key: string, title: string, value: string, state: string, text: string, step: string, anchor: ?string}>>> */
    private array $cards = [];

    /**
     * @param  Closure(list<string>, User): array<string, list<array{key: string, title: string, value: string, state: string, text: string, step: string, anchor: ?string}>>  $cards
     */
    public static function register(string $key, Closure $cards): void
    {
        self::registry()->cards[$key] = $cards;
    }

    /**
     * @param  list<string>  $sessionIds
     * @return array<string, list<array{key: string, title: string, value: string, state: string, text: string, step: string, anchor: ?string}>>
     */
    public static function for(array $sessionIds, User $viewer): array
    {
        $out = [];
        foreach (self::registry()->cards as $cards) {
            foreach ($cards($sessionIds, $viewer) as $sessionId => $list) {
                $out[$sessionId] = [...($out[$sessionId] ?? []), ...$list];
            }
        }

        return $out;
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
