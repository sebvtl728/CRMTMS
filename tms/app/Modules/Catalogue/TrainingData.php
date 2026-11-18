<?php

namespace App\Modules\Catalogue;

/**
 * A training as other modules see it (Trainings). Prices are HT per trainee
 * in integer cents, never floats; durations as stored decimals (« 14.50 »).
 */
final readonly class TrainingData
{
    public function __construct(
        public string $id,
        public ?string $code,
        public string $title,
        public string $das_id,
        public ?string $das_label,
        public ?string $duration_hours,
        public ?string $duration_days,
        public ?int $price_company_cents,
        public ?int $price_individual_cents,
        public bool $is_active,
        /** Last change, to the second (« 2026-11-14 10:30:00 », app timezone): tells whether it changed since. */
        public ?string $updated_at = null,
        /** The RNCP code of the certification (« RNCP36004 »), if any. */
        public ?string $rncp_code = null,
    ) {}

    public static function fromModel(Training $training): self
    {
        return new self(
            id: $training->id,
            code: $training->code,
            title: $training->title,
            das_id: $training->das_id,
            das_label: $training->das?->label,
            duration_hours: $training->duration_hours,
            duration_days: $training->duration_days,
            price_company_cents: self::cents($training->price_company),
            price_individual_cents: self::cents($training->price_individual),
            is_active: $training->is_active,
            updated_at: $training->updated_at?->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            rncp_code: $training->rncp_code,
        );
    }

    /**
     * « 1250.50 » (numeric(12,2) as a string) → 125050, without a float.
     */
    public static function cents(?string $amount): ?int
    {
        if ($amount === null || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
