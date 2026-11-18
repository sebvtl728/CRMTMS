<?php

namespace App\Modules\Sessions\Public;

use Closure;
use InvalidArgumentException;

/**
 * Changes of a dossier the invoicing (Finance, 0.9) may refuse, without
 * Sessions knowing it: a module registers a closure per hook, receiving the
 * id of the dossier (of the funding for FUNDER_LOCKED) and returning the
 * French refusal, or null to let it be.
 *
 *  - FUNDING_LOCKED: changing « paie directement l'organisme » of one of
 *    its fundings (FundingWriter, under the lock of the dossier);
 *  - DOSSIER_TRASHABLE: putting the dossier in the trash (DossierWriter);
 *  - PARTIES_LOCKED: changing the issuing company or the contractor of the
 *    dossier (DossierWriter, under the lock of the dossier);
 *  - FUNDER_LOCKED: changing the funder of a funding (FundingWriter, under
 *    the lock of the dossier) — the closure receives the funding's id.
 *
 * The first refusal wins. The registry lives in the application container:
 * a fresh application (each test) starts with what the providers register.
 */
final class BillingGuards
{
    public const FUNDING_LOCKED = 'fundingLocked';

    public const DOSSIER_TRASHABLE = 'dossierTrashable';

    public const PARTIES_LOCKED = 'partiesLocked';

    public const FUNDER_LOCKED = 'funderLocked';

    private const HOOKS = [self::FUNDING_LOCKED, self::DOSSIER_TRASHABLE, self::PARTIES_LOCKED, self::FUNDER_LOCKED];

    /** @var array<string, array<string, Closure(string): ?string>> hook => key => guard */
    private array $guards = [];

    /**
     * @param  string  $hook  one of self::HOOKS
     * @param  string  $key  unique name (module.guard); a second registration replaces the first
     * @param  Closure(string): ?string  $guard
     */
    public static function register(string $hook, string $key, Closure $guard): void
    {
        if (! in_array($hook, self::HOOKS, true)) {
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

    /**
     * Why the company or the contractor of $dossierId may not change, or null.
     */
    public static function partiesLocked(string $dossierId): ?string
    {
        return self::refusal(self::PARTIES_LOCKED, $dossierId);
    }

    /**
     * Why the funder of the funding $fundingId may not change, or null.
     */
    public static function funderLocked(string $fundingId): ?string
    {
        return self::refusal(self::FUNDER_LOCKED, $fundingId);
    }

    private static function refusal(string $hook, string $id): ?string
    {
        foreach (self::registry()->guards[$hook] ?? [] as $guard) {
            $refusal = $guard($id);
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
