<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Modules\SessionModules;

/**
 * What the assistant would create: one row per day and slot (skipped ones
 * with their reason: holiday, closure, common placement, existing séance),
 * the minutes it adds, the module's planned and already planned minutes
 * (null planned: « à définir »), the alerts of the séances it would create,
 * the day of its last séance (the estimated end) and whether the planned
 * hours are reached.
 */
final readonly class GenerationPreview
{
    /**
     * @param  list<array{date: string, start: string, end: string, minutes: int, skipped_reason: ?string}>  $seances
     * @param  list<Conflict>  $alerts
     */
    public function __construct(
        public array $seances,
        public int $total_minutes,
        public ?int $planned_minutes,
        public int $existing_minutes,
        public array $alerts,
        public ?string $warning,
        public ?string $estimated_end = null,
        public bool $reached = false,
    ) {}

    /**
     * @return list<array{date: string, start: string, end: string, minutes: int, skipped_reason: ?string}>
     */
    public function kept(): array
    {
        return array_values(array_filter($this->seances, fn (array $s) => $s['skipped_reason'] === null));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seances' => $this->seances,
            'count' => count($this->kept()),
            'total_minutes' => $this->total_minutes,
            'total' => SessionModules::label($this->total_minutes),
            'planned_minutes' => $this->planned_minutes,
            'planned' => $this->planned_minutes === null ? 'à définir' : SessionModules::label($this->planned_minutes),
            'existing_minutes' => $this->existing_minutes,
            'existing' => SessionModules::label($this->existing_minutes),
            'alerts' => array_map(fn (Conflict $c) => $c->toArray(), $this->alerts),
            'warning' => $this->warning,
            'estimated_end' => $this->estimated_end,
            'reached' => $this->reached,
        ];
    }
}
