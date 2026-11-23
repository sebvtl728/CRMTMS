<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Core\Users\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What other modules do once a dossier exists (Documents: the automatic
 * convention), without Sessions knowing them. Each hook runs after the
 * commit of the creation; one that fails is logged (key, class) and never
 * undoes the dossier nor stops the others.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class DossierHooks
{
    /** @var array<string, Closure(string, User): void> */
    private array $createdFromQuote = [];

    /**
     * @param  Closure(string, User): void  $hook  receives the dossier id and the user who created it
     */
    public static function onCreatedFromQuote(string $key, Closure $hook): void
    {
        self::registry()->createdFromQuote[$key] = $hook;
    }

    /** A dossier was created from an accepted quote by $by (DossierFromQuote). */
    public static function createdFromQuote(string $dossierId, User $by): void
    {
        DB::afterCommit(function () use ($dossierId, $by): void {
            foreach (self::registry()->createdFromQuote as $key => $hook) {
                try {
                    $hook($dossierId, $by);
                } catch (Throwable $e) {
                    Log::warning('Dossier hook failed.', ['hook' => $key, 'dossier' => $dossierId, 'error' => $e::class]);
                }
            }
        });
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
