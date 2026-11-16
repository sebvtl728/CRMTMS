<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Timeline\TimelineCursor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * Entries other modules add to the « Historique » of a fiche (dossiers,
 * inscriptions…), without naming the timeline of the CRM: each registers
 * one entry type and a closure receiving the request (type and id of the
 * fiche, viewer, position, limit) and answering its entries, most recent
 * first, those the viewer may see only. Read by Timeline\ModuleHistoryProvider
 * (tagged Timeline::TAG).
 *
 * @phpstan-type HistoryEntry array{at: CarbonImmutable, title: string, body?: string|null, author?: string|null, meta: array<string, mixed>}
 */
final class FicheHistory
{
    /** @var array<string, Closure(self): list<array{at: CarbonImmutable, title: string, body?: string|null, author?: string|null, meta: array<string, mixed>}>> entry type => source */
    private array $sources = [];

    /**
     * @param  'organisation'|'person'  $ficheType
     */
    public function __construct(
        public readonly string $ficheType = 'person',
        public readonly string $ficheId = '',
        public readonly ?User $viewer = null,
        public readonly int $limit = 30,
        public readonly string $type = '',
        private readonly ?TimelineCursor $before = null,
    ) {}

    /**
     * @param  string  $type  entry type (unique; meta.id of each entry is required: it orders the feed)
     * @param  Closure(self): list<array{at: CarbonImmutable, title: string, body?: string|null, author?: string|null, meta: array<string, mixed>}>  $source
     */
    public static function register(string $type, Closure $source): void
    {
        self::registry()->sources[$type] = $source;
    }

    /**
     * Restricts the source's query to the rows after the position asked for
     * (feed order: $atColumn desc, then $idColumn desc).
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function after(QueryBuilder $query, string $atColumn = 'created_at', string $idColumn = 'id'): QueryBuilder
    {
        return Timeline::after($query, $this->before, $this->type, $atColumn, $idColumn);
    }

    /**
     * Entries of every source for a fiche, keyed by entry type.
     *
     * @param  'organisation'|'person'  $ficheType
     * @return array<string, list<array{at: CarbonImmutable, title: string, body?: string|null, author?: string|null, meta: array<string, mixed>}>>
     */
    public static function entries(string $ficheType, string $ficheId, User $viewer, ?TimelineCursor $before, int $limit): array
    {
        $entries = [];
        foreach (self::registry()->sources as $type => $source) {
            $entries[$type] = $source(new self($ficheType, $ficheId, $viewer, $limit, $type, $before));
        }

        return $entries;
    }

    private static function registry(): self
    {
        $app = app();
        if (! $app->bound(self::class.'.registry')) {
            $app->instance(self::class.'.registry', new self);
        }

        /** @var self */
        return $app->make(self::class.'.registry');
    }
}
