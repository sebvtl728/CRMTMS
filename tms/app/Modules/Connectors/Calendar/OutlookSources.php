<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Users\User;
use Closure;
use InvalidArgumentException;

/**
 * Sources of Outlook events other than the CRM actions, without Connectors
 * knowing their module (Sessions: « sessions.seance », the séances of a
 * trainer). Each registers $find(string $id): the CURRENT item and its
 * recipient, or null when it no longer has a copy anywhere. The module
 * calls push() after commit on every change; PushSourceToOutlook brings
 * the copies in line with what $find answers then. Optionally:
 *
 *  - $serves(User): the source has items for the user (a trainer), which
 *    offers him « Connecter Outlook » even without the CRM actions;
 *  - $connected(string $userId): the user has just connected Outlook, the
 *    source pushes what he already has to come.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class OutlookSources
{
    /** @var array<string, array{find: Closure(string): ?OutlookItem, serves: (Closure(User): bool)|null, connected: (Closure(string): void)|null}> */
    private array $sources = [];

    /**
     * @param  string  $source  unique name (module.source, ≤ 40); a second registration replaces the first
     * @param  Closure(string): ?OutlookItem  $find
     * @param  (Closure(User): bool)|null  $serves
     * @param  (Closure(string): void)|null  $connected
     */
    public static function register(string $source, Closure $find, ?Closure $serves = null, ?Closure $connected = null): void
    {
        if ($source === CalendarLinkEvent::SOURCE_ACTIVITY || mb_strlen($source) > 40) {
            throw new InvalidArgumentException("Source Outlook invalide : {$source}");
        }
        self::registry()->sources[$source] = ['find' => $find, 'serves' => $serves, 'connected' => $connected];
    }

    /**
     * A source has items for $user (« Connecter Outlook » is offered to him).
     */
    public static function serves(User $user): bool
    {
        foreach (self::registry()->sources as $source) {
            if ($source['serves'] !== null && ($source['serves'])($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user has just connected Outlook (his link is active): each source
     * pushes his items to come.
     */
    public static function connected(string $userId): void
    {
        foreach (self::registry()->sources as $source) {
            if ($source['connected'] !== null) {
                ($source['connected'])($userId);
            }
        }
    }

    /**
     * The current item, null when it has no copy (or the source is unknown).
     */
    public static function find(string $source, string $id): ?OutlookItem
    {
        $find = self::registry()->sources[$source]['find'] ?? null;

        return $find === null ? null : $find($id);
    }

    /**
     * The item changed: its copies follow (queued after commit). Nothing is
     * queued when the organisme has no usable Microsoft 365 configuration,
     * or when no one has connected Outlook and the item has no copy.
     */
    public static function push(string $source, string $id): void
    {
        if (! isset(self::registry()->sources[$source])) {
            throw new InvalidArgumentException("Source Outlook inconnue : {$source}");
        }
        if (Microsoft365Connector::activeConfig() === null) {
            return;
        }
        $concerned = CalendarLink::query()->where('status', CalendarLink::ACTIVE)->exists()
            || CalendarLinkEvent::query()->of($source, $id)->exists();
        if ($concerned) {
            PushSourceToOutlook::dispatch($source, $id)->afterCommit();
        }
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
