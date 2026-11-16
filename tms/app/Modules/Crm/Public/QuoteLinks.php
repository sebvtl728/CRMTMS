<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use Closure;

/**
 * Actions other modules add to the page of an accepted quote (« Créer le
 * dossier », « Voir le dossier »…), without the Crm knowing them: each
 * registers a closure receiving the id of the quote and the viewer, and
 * returning its link (label and URL), or null when the viewer has nothing
 * to do there (the module applies its own rights and scopes).
 *
 * The registry lives in the application container: a fresh application
 * (each test) starts with only what the providers register.
 */
final class QuoteLinks
{
    /** @var array<string, Closure(string, User): (array{label: string, href: string}|null)> */
    private array $links = [];

    /**
     * @param  string  $key  unique name (module.link); a second registration replaces the first
     * @param  Closure(string, User): (array{label: string, href: string}|null)  $link
     */
    public static function register(string $key, Closure $link): void
    {
        self::registry()->links[$key] = $link;
    }

    /**
     * The links of the accepted quote $quoteId that $viewer may follow, in
     * the order of registration.
     *
     * @return list<array{key: string, label: string, href: string}>
     */
    public static function for(string $quoteId, User $viewer): array
    {
        $links = [];
        foreach (self::registry()->links as $key => $link) {
            $data = $link($quoteId, $viewer);
            if ($data !== null) {
                $links[] = ['key' => $key, 'label' => $data['label'], 'href' => $data['href']];
            }
        }

        return $links;
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
