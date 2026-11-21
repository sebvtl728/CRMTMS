<?php

/*
 * Helpers of the online attendance signing tests, included after
 * Support/lot2a.php, Support/planning.php and Support/attendance.php.
 */

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;

/** The Paris clock set to $at (« 2026-09-10 09:30 »). */
function sgNow(string $at = '2026-09-10 09:30'): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'Europe/Paris'));
}

/**
 * Opens the half-day of the session (by a manager unless given).
 *
 * @return array{slot: SignSlot, token: string}
 */
function sgOpen(TrainingSession $session, string $date = '2026-09-10', string $half = SignSlot::MORNING, ?User $by = null): array
{
    return app(AttendanceSigning::class)->open($session, $date, $half, $by ?? atManager());
}
