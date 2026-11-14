<?php

namespace App\Modules\Catalogue;

use Closure;

/**
 * « This training is used somewhere »: each module that stores a training
 * id (opportunity lines, quote lines...) registers a check, so that the
 * catalogue refuses to delete a training in use without knowing those
 * modules (Catalogue never reads their tables).
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class TrainingUsage
{
    /** @var array<string, Closure(string): bool> */
    private array $checks = [];

    /**
     * @param  string  $name  unique name (module.data), a second registration replaces the first
     * @param  callable(string): bool  $isUsed  true when the training whose id is given is used
     */
    public static function register(string $name, callable $isUsed): void
    {
        self::registry()->checks[$name] = Closure::fromCallable($isUsed);
    }

    public static function isUsed(string $trainingId): bool
    {
        foreach (self::registry()->checks as $check) {
            if ($check($trainingId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Empties the registry (tests).
     */
    public static function flush(): void
    {
        self::registry()->checks = [];
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
