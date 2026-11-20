<?php

namespace App\Modules\Crm\Dashboard;

/**
 * The value of a dashboard indicator over a period (lot 1e): null when it
 * has no meaning (no data, a rate over nothing); amounts in integer cents.
 */
final readonly class IndicatorValue
{
    public const COUNT = 'count';

    public const CENTS = 'cents';

    public const PERCENT = 'percent';

    public const DAYS = 'days';

    /**
     * @param  list<array{label: string, value: int|float|null, extra?: int|float|null}>|null  $breakdown  rows shown under the value
     */
    public function __construct(
        public int|float|null $value,
        public string $unit,
        public ?array $breakdown = null,
        public ?int $targetCents = null,
        public ?string $note = null,
    ) {}

    /** @return array{value: int|float|null, unit: string, breakdown: list<array<string, mixed>>|null, target_cents: int|null, note: string|null} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'unit' => $this->unit, 'breakdown' => $this->breakdown, 'target_cents' => $this->targetCents, 'note' => $this->note];
    }
}
