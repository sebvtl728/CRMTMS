<?php

namespace App\Modules\Core\Todo;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * « À traiter » (2026-11-22): each module declares, in its boot, what its
 * users have to deal with — a count within the user's scope (the same query
 * as the screen it links to) and that screen. A source is evaluated only
 * for a user holding its right (and passing its own check); a failing one
 * shows « Indisponible » without breaking the page. Read-only.
 */
final class TodoSources
{
    public const GROUPS = ['Sessions', 'Documents', 'Commercial', 'Admissions', 'Catalogue', 'Finance'];

    public const TTL = 60;

    /** @var array<string, array{group: string, label: string, permission: string, count: Closure(User): int, link: Closure(User): string, visible: Closure(User): bool|null}> */
    private static array $sources = [];

    /**
     * @param  Closure(User): int  $count
     * @param  Closure(User): string  $link
     * @param  (Closure(User): bool)|null  $visible  a further check than the right (a scope, a mode…)
     */
    public static function register(string $key, string $group, string $label, string $permission, Closure $count, Closure $link, ?Closure $visible = null): void
    {
        self::$sources[$key] = ['group' => $group, 'label' => $label, 'permission' => $permission, 'count' => $count, 'link' => $link, 'visible' => $visible];
    }

    /** @return list<array{key: string, group: string, label: string, count: int|null, url: string}> */
    public static function for(User $user): array
    {
        $rows = [];
        foreach (self::$sources as $key => $source) {
            if (! $user->can($source['permission']) || ($source['visible'] !== null && ! ($source['visible'])($user))) {
                continue;
            }
            try {
                $count = ($source['count'])($user);
            } catch (Throwable $e) {
                Log::warning('Todo source failed.', ['source' => $key, 'error' => $e::class]);
                $count = null;
            }
            $rows[] = ['key' => $key, 'group' => $source['group'], 'label' => $source['label'], 'count' => $count, 'url' => ($source['link'])($user)];
        }
        usort($rows, fn (array $a, array $b) => [array_search($a['group'], self::GROUPS, true), $a['label']] <=> [array_search($b['group'], self::GROUPS, true), $b['label']]);

        return $rows;
    }

    /** The total of the menu, kept 60 s per organisme and user. */
    public static function total(User $user): int
    {
        return Cache::remember('tenant:'.app(TenantContext::class)->id().':todo:'.$user->id, self::TTL,
            fn (): int => array_sum(array_map(fn (array $r) => $r['count'] ?? 0, self::for($user))));
    }

    /** Whether the user sees at least one source (the menu entry). */
    public static function any(User $user): bool
    {
        foreach (self::$sources as $source) {
            if ($user->can($source['permission']) && ($source['visible'] === null || ($source['visible'])($user))) {
                return true;
            }
        }

        return false;
    }
}
