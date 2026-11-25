<?php

namespace App\Modules\Sessions\Planning;

/**
 * One séance of a trainee's individual planning, at his hours
 * (IndividualPlanning::seancesOf): « added » when it belongs to another
 * session (a catch-up).
 */
final readonly class PlannedSeance
{
    public function __construct(
        public Seance $seance,
        public string $start,
        public string $end,
        public int $minutes,
        public bool $added,
    ) {}
}
