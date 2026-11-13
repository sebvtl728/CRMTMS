<?php

namespace App\Modules\Core\Users;

use Illuminate\Http\Request;

/**
 * Extension point of the users page: a module (e.g. the CRM, for the « DAS
 * suivis » of a Responsable DAS) adds page props and per-user data without
 * Core knowing the module. A page prop absent from the page means the module
 * is not there and the page hides the matching column.
 */
final class UserPageExtensions
{
    /** @var list<callable(Request): array<string, mixed>> */
    private static array $props = [];

    /** @var list<callable(User): array<string, mixed>> */
    private static array $rows = [];

    /**
     * @param  callable(Request): array<string, mixed>  $provider  extra props of the page
     */
    public static function props(callable $provider): void
    {
        self::$props[] = $provider;
    }

    /**
     * @param  callable(User): array<string, mixed>  $provider  extra keys of a user row
     */
    public static function rows(callable $provider): void
    {
        self::$rows[] = $provider;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageProps(Request $request): array
    {
        return array_merge([], ...array_map(fn (callable $p) => $p($request), self::$props));
    }

    /**
     * @return array<string, mixed>
     */
    public static function rowData(User $user): array
    {
        return array_merge([], ...array_map(fn (callable $p) => $p($user), self::$rows));
    }

    public static function flush(): void
    {
        self::$props = [];
        self::$rows = [];
    }
}
