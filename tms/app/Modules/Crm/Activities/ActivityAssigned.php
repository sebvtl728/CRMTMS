<?php

namespace App\Modules\Crm\Activities;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An action to do was handed to another user than the one who did it
 * (« Attribuer à » at creation, « Confier à… », « Modifier »). Dispatched
 * once the transaction is committed; the new responsible is told by e-mail
 * (SendActivityAssigned). The optional message travels only here, never in
 * the journal.
 */
final class ActivityAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $activityId,
        public readonly string $byUserId,
        public readonly ?string $message,
    ) {}
}
