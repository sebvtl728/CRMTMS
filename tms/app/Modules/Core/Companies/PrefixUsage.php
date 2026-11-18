<?php

namespace App\Modules\Core\Companies;

use Closure;

/**
 * Whether a company already numbered pieces of a kind with a prefix, told by
 * the modules that number them (Finance, 0.9: invoices and credit notes),
 * without Core knowing their tables. The company form refuses a prefix that
 * numbered the other kind of piece of the company (two numberings would
 * meet: « AV-2026-00001 » twice).
 *
 * A module registers a closure (company id, kind, prefix) => bool. The
 * registry lives in the application container: a fresh application (each
 * test) starts with what the providers register.
 */
final class PrefixUsage
{
    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    /** @var array<string, Closure(string, string, string): bool> */
    private array $checks = [];

    /**
     * @param  string  $key  unique name (module.check); a second registration replaces the first
     * @param  Closure(string, string, string): bool  $numbered  (company id, self::INVOICE|self::CREDIT_NOTE, prefix): a piece of that kind bears the prefix
     */
    public static function register(string $key, Closure $numbered): void
    {
        self::registry()->checks[$key] = $numbered;
    }

    /**
     * $prefix already numbered pieces of the company of a kind other than $kind.
     */
    public static function usedByOtherKind(string $companyId, string $kind, string $prefix): bool
    {
        $other = $kind === self::INVOICE ? self::CREDIT_NOTE : self::INVOICE;
        foreach (self::registry()->checks as $numbered) {
            if ($numbered($companyId, $other, $prefix)) {
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
