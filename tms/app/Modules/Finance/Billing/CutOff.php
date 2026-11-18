<?php

namespace App\Modules\Finance\Billing;

use InvalidArgumentException;

/**
 * The date up to which a dossier is billed (spec 0.9 § 4.3), a Paris day:
 *
 *  - on(): a day (« end »: the day the computation is made, the sessions must
 *    be over before it; « schedule »: the instalments due on or before it);
 *  - month(): a closed month of attendance (« monthly »), its last day as
 *    the date (an abandon left on or before it costs its dédit).
 */
final readonly class CutOff
{
    private function __construct(
        public string $date,
        public ?string $month,
    ) {}

    /**
     * @throws InvalidArgumentException a malformed day (« 2026-10-15 »)
     */
    public static function on(string $date): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Date invalide : {$date}");
        }

        return new self($date, null);
    }

    /**
     * @throws InvalidArgumentException a malformed month (« 2026-10 »)
     */
    public static function month(string $month): self
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1) {
            throw new InvalidArgumentException("Mois invalide : {$month}");
        }
        $days = (int) gmdate('t', gmmktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]));

        return new self("{$month}-{$days}", $month);
    }
}
