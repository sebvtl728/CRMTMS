<?php

namespace App\Modules\Connectors\Calendar;

use Closure;
use InvalidArgumentException;

/**
 * Sources of Outlook events other than the CRM actions, without Connectors
 * knowing their module (Sessions: « sessions.seance », the séances of a
 * trainer). Each registers $find(string $id): the CURRENT item and its
 * recipient, or null when it no longer has a copy anywhere. The module
 * calls push() after commit on every change; PushSourceToOutlook brings
 * the copies in line with what $find answers then.
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class OutlookSources
{
    /** @var array<string, Closure(string): ?OutlookItem> */
    private array $sources = [];

    /**
     * @param  string  $source  unique name (module.source, ≤ 40); a second registration replaces the first
     * @param  Closure(string): ?OutlookItem  $find
     */
    public static function register(string $source, Closure $find): void
    {
        if ($source === CalendarLinkEvent::SOURCE_ACTIVITY || mb_strlen($source) > 40) {
            throw new InvalidArgumentException("Source Outlook invalide : {$source}");
        }
        self::registry()->sources[$source] = $find;
    }

    /**
     * The current item, null when it has no copy (or the source is unknown).
     */
    public static function find(string $source, string $id): ?OutlookItem
    {
        $find = self::registry()->sources[$source] ?? null;

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
