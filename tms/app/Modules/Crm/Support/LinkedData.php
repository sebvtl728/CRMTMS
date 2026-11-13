<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Closure;

/**
 * « Something hangs on this fiche »: each module that attaches data to an
 * entreprise or a person (links, notes, files, consents, opportunities,
 * actions...) registers a check, so that the undo of an import never trashes
 * a fiche users built on, without the import knowing every module.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class LinkedData
{
    /** @var array<string, Closure(Organisation|Person, list<string>): bool> */
    private array $checks = [];

    /**
     * @param  string  $key  unique name (module.data), a second registration replaces the first
     * @param  Closure(Organisation|Person, list<string>): bool  $hasLinked  true when data other than the rows whose ids are in the list hangs on the fiche
     */
    public static function register(string $key, Closure $hasLinked): void
    {
        self::registry()->checks[$key] = $hasLinked;
    }

    public static function forget(string $key): void
    {
        unset(self::registry()->checks[$key]);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::registry()->checks);
    }

    /**
     * Whether any module holds data on $fiche, rows whose id is in
     * $exceptIds aside.
     *
     * @param  list<string>  $exceptIds
     */
    public static function any(Organisation|Person $fiche, array $exceptIds = []): bool
    {
        foreach (self::registry()->checks as $check) {
            if ($check($fiche, $exceptIds)) {
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
