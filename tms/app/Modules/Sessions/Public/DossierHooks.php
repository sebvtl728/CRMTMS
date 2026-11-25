<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Core\Users\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What other modules do once a dossier exists (Documents: the automatic
 * convention of a quote, the alternance convention of a candidature), without Sessions knowing them. Each hook runs after the
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

    /** @var array<string, Closure(string, User): void> */
    private array $createdFromCandidature = [];

    /**
     * @param  Closure(string, User): void  $hook  receives the dossier id and the user who created it
     */
    public static function onCreatedFromCandidature(string $key, Closure $hook): void
    {
        self::registry()->createdFromCandidature[$key] = $hook;
    }

    /** A dossier was created for an admitted candidate by $by (Admission::createDossier). */
    public static function createdFromCandidature(string $dossierId, User $by): void
    {
        self::run(self::registry()->createdFromCandidature, $dossierId, $by);
    }

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
        self::run(self::registry()->createdFromQuote, $dossierId, $by);
    }

    /**
     * @param  array<string, Closure(string, User): void>  $hooks
     */
    private static function run(array $hooks, string $dossierId, User $by): void
    {
        DB::afterCommit(function () use ($hooks, $dossierId, $by): void {
            foreach ($hooks as $key => $hook) {
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
