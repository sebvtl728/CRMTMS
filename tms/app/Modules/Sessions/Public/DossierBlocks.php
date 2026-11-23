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
 * lines). The dossier page renders them all the same way, unless the block
 * names an interactive component (« component », with its « props »: the
 * billing settings of Finance), which the page looks up in its registry of
 * block components (resources/js/components/dossier-blocks.tsx) and shows
 * above the rows.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class DossierBlocks
{
    /** @var array<string, Closure(string, User): (array{title: string, empty: string, rows: list<array<string, mixed>>, component?: string, props?: array<string, mixed>}|null)> */
    private array $blocks = [];

    /** @var array<string, int> */
    private array $positions = [];

    /**
     * @param  string  $key  unique name (module.block); a second registration replaces the first
     * @param  Closure(string, User): (array{title: string, empty: string, rows: list<array<string, mixed>>, component?: string, props?: array<string, mixed>}|null)  $block
     * @param  int  $position  order on the page, smallest first (the dossier's life: candidature, documents, then billing)
     */
    public static function register(string $key, Closure $block, int $position = 100): void
    {
        self::registry()->blocks[$key] = $block;
        self::registry()->positions[$key] = $position;
    }

    /** The position a block was registered with; null when it is not registered. */
    public static function positionOf(string $key): ?int
    {
        return self::registry()->positions[$key] ?? null;
    }

    /**
     * The blocks of the dossier $dossierId that $viewer may see, by position,
     * then in the order of registration.
     *
     * @return list<array{key: string, title: string, empty: string, rows: list<array<string, mixed>>, component?: string, props?: array<string, mixed>}>
     */
    public static function for(string $dossierId, User $viewer): array
    {
        $blocks = [];
        $registry = self::registry();
        $keys = array_keys($registry->blocks);
        $rank = array_flip($keys);
        usort($keys, fn (string $a, string $b) => [$registry->positions[$a], $rank[$a]] <=> [$registry->positions[$b], $rank[$b]]);
        foreach ($keys as $key) {
            $block = $registry->blocks[$key];
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
