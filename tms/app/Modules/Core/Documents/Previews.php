<?php

namespace App\Modules\Core\Documents;

use Closure;
use RuntimeException;

/**
 * Previews of documents a module renders for another one without either
 * knowing the other (Documents renders the alternance convention that
 * Admissions shows before the dossier exists). A preview is a PDF, never
 * stored nor numbered. Kept in the container (one per application).
 */
final class Previews
{
    /** @var array<string, Closure(array<string, mixed>): string> */
    private array $renderers = [];

    /**
     * @param  Closure(array<string, mixed>): string  $renderer  data => PDF bytes
     */
    public static function register(string $key, Closure $renderer): void
    {
        self::registry()->renderers[$key] = $renderer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $key, array $data): string
    {
        $renderer = self::registry()->renderers[$key] ?? throw new RuntimeException("No preview « {$key} ».");

        return $renderer($data);
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
