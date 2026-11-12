<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Access\PrimaryRoleChanged;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * access.role_changed. Runs after the surrounding transaction commits (e.g.
 * the activation transaction): a rolled-back role change leaves no row.
 */
class RecordRoleChange implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(PrimaryRoleChanged $event): void
    {
        $this->recorder->record(
            'access.role_changed',
            $event->user,
            ['role' => $event->previous?->value],
            ['role' => $event->role->value],
        );
    }
}
