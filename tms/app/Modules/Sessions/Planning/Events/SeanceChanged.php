<?php

namespace App\Modules\Sessions\Planning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A séance changed its day or hours (rescheduled) or was cancelled:
 * dispatched once the transaction is committed. The attendance (task 4)
 * listens: attendances put « à revoir », present recomputed, partial capped;
 * a cancellation sets them to 0 minute. Ids only.
 */
final class SeanceChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    /** Only for the schedule hooks (Seances::onScheduleChange): never dispatched. */
    public const DELETED = 'deleted';

    public function __construct(
        public readonly string $seanceId,
        public readonly string $kind,
    ) {}
}
