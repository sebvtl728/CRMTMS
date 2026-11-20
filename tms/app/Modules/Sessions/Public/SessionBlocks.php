<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Core\Users\User;
use Closure;

/**
 * Blocks other modules add to the page of a session (« Documents » of the
 * Documents module…), shown on the « Stagiaires » step, without Sessions
 * knowing them: each registers a closure receiving the id of the session and
 * the viewer, returning its block (title, component, props) or null when the
 * viewer may not see it. The page looks the component up in its registry
 * (resources/js/components/session-blocks.tsx).
 */
final class SessionBlocks
{
    /** @var array<string, Closure(string, User): (array{title: string, component: string, props: array<string, mixed>}|null)> */
    private array $blocks = [];

    /**
     * @param  Closure(string, User): (array{title: string, component: string, props: array<string, mixed>}|null)  $block
     */
    public static function register(string $key, Closure $block): void
    {
        self::registry()->blocks[$key] = $block;
    }

    /**
     * @return list<array{key: string, title: string, component: string, props: array<string, mixed>}>
     */
    public static function for(string $sessionId, User $viewer): array
    {
        $blocks = [];
        foreach (self::registry()->blocks as $key => $block) {
            $data = $block($sessionId, $viewer);
            if ($data !== null) {
                $blocks[] = ['key' => $key, ...$data];
            }
        }

        return $blocks;
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
