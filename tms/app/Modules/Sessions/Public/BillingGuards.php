<?php

namespace App\Modules\Sessions\Public;

use Closure;
use InvalidArgumentException;

/**
 * Changes of a dossier the invoicing (Finance, 0.9) may refuse, without
 * Sessions knowing it: a module registers a closure per hook, receiving the
 * id of the dossier and returning the French refusal, or null to let it be.
 *
 *  - FUNDING_LOCKED: changing « paie directement l'organisme » of one of
 *    its fundings (FundingWriter, under the lock of the dossier);
 *  - DOSSIER_TRASHABLE: putting the dossier in the trash (DossierWriter).
 *
 * The first refusal wins. The registry lives in the application container:
 * a fresh application (each test) starts with what the providers register.
 */
final class BillingGuards
{
    public const FUNDING_LOCKED = 'fundingLocked';

    public const DOSSIER_TRASHABLE = 'dossierTrashable';

    /** @var array<string, array<string, Closure(string): ?string>> hook => key => guard */
    private array $guards = [];

    /**
     * @param  string  $hook  self::FUNDING_LOCKED or self::DOSSIER_TRASHABLE
     * @param  string  $key  unique name (module.guard); a second registration replaces the first
     * @param  Closure(string): ?string  $guard
     */
    public static function register(string $hook, string $key, Closure $guard): void
    {
        if (! in_array($hook, [self::FUNDING_LOCKED, self::DOSSIER_TRASHABLE], true)) {
            throw new InvalidArgumentException("Unknown billing guard hook « {$hook} ».");
        }
        self::registry()->guards[$hook][$key] = $guard;
    }

    /**
     * Why « paie directement » of a funding of $dossierId may not change, or null.
     */
    public static function fundingLocked(string $dossierId): ?string
    {
        return self::refusal(self::FUNDING_LOCKED, $dossierId);
    }

    /**
     * Why $dossierId may not go to the trash, or null.
     */
    public static function dossierTrashable(string $dossierId): ?string
    {
        return self::refusal(self::DOSSIER_TRASHABLE, $dossierId);
    }

    private static function refusal(string $hook, string $dossierId): ?string
    {
        foreach (self::registry()->guards[$hook] ?? [] as $guard) {
            $refusal = $guard($dossierId);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
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
