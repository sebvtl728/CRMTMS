<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use Closure;
use InvalidArgumentException;

/**
 * Blocks other modules add to the page of a fiche (« Formations suivies »
 * on a person, « Dossiers » on an entreprise…), without the Crm knowing
 * them: each registers, for a type of fiche, a closure receiving the id of
 * the fiche and the viewer, returning its block, or null when the viewer
 * may not see it (the module applies its own rights and scopes).
 *
 * A block: title, the text shown when it is empty, and rows (title, optional
 * subtitle, meta, badge, link and detail lines). The Crm renders them all
 * the same way.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 *
 * @phpstan-type BlockRow array{id: string, title: string, subtitle?: string|null, meta?: string|null, badge?: string|null, href?: string|null, details?: list<string>}
 * @phpstan-type Block array{key: string, title: string, empty: string, rows: list<BlockRow>}
 */
final class FicheBlocks
{
    public const TYPES = ['organisation', 'person'];

    /** @var array<string, array<string, Closure(string, User): (array{title: string, empty: string, rows: list<array<string, mixed>>}|null)>> */
    private array $blocks = [];

    /**
     * @param  string  $type  organisation|person
     * @param  string  $key  unique name (module.block); a second registration replaces the first
     * @param  Closure(string, User): (array{title: string, empty: string, rows: list<array<string, mixed>>}|null)  $block
     */
    public static function register(string $type, string $key, Closure $block): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Type de fiche inconnu : {$type}");
        }
        self::registry()->blocks[$type][$key] = $block;
    }

    /**
     * The blocks of the fiche $id of $type that $viewer may see, in the order
     * of registration.
     *
     * @param  'organisation'|'person'  $type
     * @return list<array{key: string, title: string, empty: string, rows: list<array<string, mixed>>}>
     */
    public static function for(string $type, string $id, User $viewer): array
    {
        $blocks = [];
        foreach (self::registry()->blocks[$type] ?? [] as $key => $block) {
            $data = $block($id, $viewer);
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
