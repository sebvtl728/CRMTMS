<?php

namespace App\Modules\Crm\Activities\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An action was created, changed in what a calendar shows of it (type,
 * date, duration, place, fiches, responsible), deleted or restored.
 * Dispatched once the transaction is committed. Carries ids only: a
 * listener reads the current state through CalendarEvents::find().
 */
final class ActivityChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $activityId,
        public readonly ?string $responsibleUserId,
        public readonly ?string $previousResponsibleUserId = null,
    ) {}
}
