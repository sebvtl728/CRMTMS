<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Core\Users\User;
use Closure;

/**
 * Blocks other modules add to the page of a dossier (« Factures » of the
 * Finance module…), without Sessions knowing them: each registers a closure
 * receiving the id of the dossier and the viewer, returning its block, or
 * null when the viewer may not see it (the module applies its own rights and
 * scopes). Same shape as Crm\Public\FicheBlocks: title, the text shown when
 * it is empty, rows (title, optional subtitle, meta, badge, link and detail
 * lines). The dossier page renders them all the same way.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class DossierBlocks
{
    /** @var array<string, Closure(string, User): (array{title: string, empty: string, rows: list<array<string, mixed>>}|null)> */
    private array $blocks = [];

    /**
     * @param  string  $key  unique name (module.block); a second registration replaces the first
     * @param  Closure(string, User): (array{title: string, empty: string, rows: list<array<string, mixed>>}|null)  $block
     */
    public static function register(string $key, Closure $block): void
    {
        self::registry()->blocks[$key] = $block;
    }

    /**
     * The blocks of the dossier $dossierId that $viewer may see, in the order
     * of registration.
     *
     * @return list<array{key: string, title: string, empty: string, rows: list<array<string, mixed>>}>
     */
    public static function for(string $dossierId, User $viewer): array
    {
        $blocks = [];
        foreach (self::registry()->blocks as $key => $block) {
            $data = $block($dossierId, $viewer);
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
