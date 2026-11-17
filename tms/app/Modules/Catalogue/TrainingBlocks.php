<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Users\User;
use Closure;

/**
 * Blocks other modules add to the page of a training (« Sessions »…),
 * without the Catalogue knowing them (same pattern as the Crm's
 * FicheBlocks): each module registers a closure receiving the id of the
 * training and the viewer, returning its block (title and data), or null
 * when the viewer may not see it (the module applies its own rights and
 * scopes).
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class TrainingBlocks
{
    /** @var array<string, Closure(string, User): (array{title: string, data: array<string, mixed>}|null)> */
    private array $blocks = [];

    /**
     * @param  string  $key  unique name; a second registration replaces the first
     * @param  Closure(string, User): (array{title: string, data: array<string, mixed>}|null)  $block
     */
    public static function register(string $key, Closure $block): void
    {
        self::registry()->blocks[$key] = $block;
    }

    /**
     * The blocks of the training that $viewer may see, in the order of registration.
     *
     * @return list<array{key: string, title: string, data: array<string, mixed>}>
     */
    public static function for(Training|string $trainingId, User $viewer): array
    {
        $id = $trainingId instanceof Training ? $trainingId->id : $trainingId;
        $blocks = [];
        foreach (self::registry()->blocks as $key => $block) {
            $result = $block($id, $viewer);
            if ($result !== null) {
                $blocks[] = ['key' => $key, ...$result];
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
